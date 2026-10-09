<?php

/**
 * Run after 005. Pass explicit project choices for groups without assignments.
 * Multi-project groups are split, preserving memberships and task assignments.
 */
final class LocalGroupMigration {
    public function __construct(private PDO $db) {}

    public function plan(array $unassignedProjects): array {
        $groups = $this->db->query('SELECT id, name, personal_user_id, project_id, is_global FROM groups ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        $projects = $this->db->query('SELECT id, archived_at FROM projects')->fetchAll(PDO::FETCH_KEY_PAIR);
        $links = $this->db->prepare('SELECT project_id FROM projects_groups WHERE group_id = :id ORDER BY project_id');
        $taskProjects = $this->db->prepare('SELECT DISTINCT c.project_id FROM groups_tasks gt
            JOIN tasks t ON t.id = gt.task_id JOIN containers c ON c.id = t.container_id
            WHERE gt.group_id = :id');
        $nameCollision = $this->db->prepare('SELECT id FROM groups
            WHERE project_id = :project AND name = :name AND id <> :id');
        $plan = [];
        foreach ($groups as $group) {
            if ($group['personal_user_id'] !== null || (bool) $group['is_global']) continue;
            $id = (int) $group['id'];
            $links->execute(['id' => $id]);
            $assigned = array_map('intval', $links->fetchAll(PDO::FETCH_COLUMN));
            $taskProjects->execute(['id' => $id]);
            $used = array_map('intval', $taskProjects->fetchAll(PDO::FETCH_COLUMN));
            // Already localized groups are validated but never cloned again on reruns.
            if ($group['project_id'] !== null) {
                $localProject = (int) $group['project_id'];
                if (array_diff($assigned, [$localProject]) || array_diff($used, [$localProject])) {
                    throw new RuntimeException("Local group $id has foreign project assignments");
                }
                if (isset($unassignedProjects[$id]) && (int) $unassignedProjects[$id] !== $localProject) {
                    throw new RuntimeException("Group $id is already local to a different project");
                }
                continue;
            }
            if ($assigned === []) {
                if (!isset($unassignedProjects[$id])) {
                    throw new RuntimeException("Project choice required for unassigned group $id");
                }
                $assigned = [(int) $unassignedProjects[$id]];
                if (!array_key_exists($assigned[0], $projects) || $projects[$assigned[0]] !== null) {
                    throw new RuntimeException("Active target project required for group $id");
                }
            } elseif (isset($unassignedProjects[$id])) {
                throw new RuntimeException("Group $id already has project assignments; do not override them");
            }
            if (array_diff($used, $assigned)) {
                throw new RuntimeException("Group $id has tasks outside its project assignments");
            }
            foreach ($assigned as $projectId) {
                if (!array_key_exists($projectId, $projects)) {
                    throw new RuntimeException("Project $projectId does not exist");
                }
                $nameCollision->execute(['project' => $projectId, 'name' => $group['name'], 'id' => $id]);
                if ($nameCollision->fetchColumn()) {
                    throw new RuntimeException("Group name collision in project $projectId for group $id");
                }
            }
            $plan[] = ['id' => $id, 'name' => $group['name'], 'projects' => $assigned];
        }
        foreach ($unassignedProjects as $id => $projectId) {
            if (!in_array((int) $id, array_map(static fn(array $g): int => (int) $g['id'], $groups), true)) {
                throw new RuntimeException("Unknown group $id in project choices");
            }
        }
        return $plan;
    }

    public function apply(array $unassignedProjects): array {
        $this->db->beginTransaction();
        try {
            // Lock the source rows before copying memberships and rewiring assignments.
            // A maintenance window is still required: projects and containers are not locked.
            foreach (['groups', 'projects_groups', 'users_groups', 'groups_tasks'] as $table) {
                $this->db->query("SELECT * FROM `$table` FOR UPDATE")->fetchAll();
            }
            $plan = $this->plan($unassignedProjects);
            $result = [];
            $makeLocal = $this->db->prepare('UPDATE groups SET project_id = :project, is_global = 0 WHERE id = :id');
            $copy = $this->db->prepare('INSERT INTO groups (name, project_id, is_global, created_at)
                SELECT name, :project, 0, created_at FROM groups WHERE id = :id');
            $members = $this->db->prepare('INSERT INTO users_groups (user_id, groups_id, role, assignment_date)
                SELECT user_id, :new_id, role, assignment_date FROM users_groups WHERE groups_id = :old_id');
            $link = $this->db->prepare('INSERT INTO projects_groups (project_id, group_id) VALUES (:project, :group_id)');
            $moveTasks = $this->db->prepare('UPDATE groups_tasks gt
                JOIN tasks t ON t.id = gt.task_id JOIN containers c ON c.id = t.container_id
                SET gt.group_id = :new_id WHERE gt.group_id = :old_id AND c.project_id = :project');
            $unlink = $this->db->prepare('DELETE FROM projects_groups WHERE project_id = :project AND group_id = :id');
            foreach ($plan as $group) {
                foreach ($group['projects'] as $offset => $projectId) {
                    if ($offset === 0) {
                        // Ordered project links keep the original ID on the lowest project ID.
                        $makeLocal->execute(['project' => $projectId, 'id' => $group['id']]);
                        $localId = $group['id'];
                        $existingLink = $this->db->prepare('SELECT 1 FROM projects_groups WHERE project_id = :project AND group_id = :id');
                        $existingLink->execute(['project' => $projectId, 'id' => $localId]);
                        if (!$existingLink->fetchColumn()) {
                            $link->execute(['project' => $projectId, 'group_id' => $localId]);
                        }
                    } else {
                        $copy->execute(['project' => $projectId, 'id' => $group['id']]);
                        $localId = (int) $this->db->lastInsertId();
                        $members->execute(['new_id' => $localId, 'old_id' => $group['id']]);
                        $link->execute(['project' => $projectId, 'group_id' => $localId]);
                        $moveTasks->execute(['new_id' => $localId, 'old_id' => $group['id'], 'project' => $projectId]);
                        $unlink->execute(['project' => $projectId, 'id' => $group['id']]);
                    }
                    $result[] = ['source_group' => $group['id'], 'local_group' => $localId, 'project' => $projectId];
                }
            }
            $this->db->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__ . '/../../../vendor/autoload.php';
    BienenPlan\Services\Env::load();
    $choices = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply') continue;
        if (!preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D', $argument, $matches)) {
            throw new InvalidArgumentException('Expected group_id:project_id or --apply');
        }
        if (isset($choices[(int) $matches[1]])) {
            throw new InvalidArgumentException('Duplicate group choice');
        }
        $choices[(int) $matches[1]] = (int) $matches[2];
    }
    $migration = new LocalGroupMigration(BienenPlan\Config\Database::getConnection());
    $result = in_array('--apply', $argv, true) ? $migration->apply($choices) : $migration->plan($choices);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
}
