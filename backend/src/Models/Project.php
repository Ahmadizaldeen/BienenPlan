<?php

namespace BienenPlan\Models;

use PDO;
use BienenPlan\Services\AccessService;

class Project {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // CREATE
    public function create(array $data): int {
        $sql = "INSERT INTO projects (name, created_by) 
                SELECT :name, id FROM users WHERE id = :created_by AND deleted_at IS NULL";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'name'        => $data['name'],
            'created_by'  => $data['created_by']
        ]);
        if ($stmt->rowCount() !== 1) throw new \DomainException('Benutzer nicht mehr aktiv');

        return (int) $this->pdo->lastInsertId();
    }

    // Owners see all active projects; members see projects linked to one of their groups.
    private function permissionColumns(): string {
        return 'p.created_by = a.id AS is_owner,
            (p.archived_at IS NULL AND ' . AccessService::PROJECT_MANAGE_SQL . ') AS can_edit,
            (p.archived_at IS NULL AND ' . AccessService::PROJECT_MANAGE_SQL . ') AS can_delete,
            (p.archived_at IS NULL AND ' . AccessService::PROJECT_MANAGE_SQL . ') AS can_manage_groups,
            (p.archived_at IS NOT NULL AND a.is_admin = 1) AS can_restore';
    }

    public function getAll(int $userId, bool $archived = false): array {
        $sql = "SELECT DISTINCT p.*, " . $this->permissionColumns() . ", u.name AS creator_name
                FROM projects p
                JOIN users u ON p.created_by = u.id
                " . AccessService::ACTOR_JOIN . "
                WHERE " . ($archived ? 'p.archived_at IS NOT NULL AND a.is_admin = 1' :
                    'p.archived_at IS NULL AND ' . AccessService::PROJECT_VIEW_SQL) . "
                ORDER BY " . ($archived ? 'p.archived_at' : 'p.created_at') . " DESC, p.id DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['access_user' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isVisibleToUser(int $projectId, int $userId): bool {
        return (new AccessService($this->pdo))->canViewProject($userId, $projectId);
    }

    public function isOwner(int $projectId, int $userId): bool {
        return (new AccessService($this->pdo))->canManageProject($userId, $projectId);
    }

    public function getGroups(int $projectId, ?int $viewerId = null): array {
        $stmt = $this->pdo->prepare(
            'SELECT g.id, g.name, g.personal_user_id, g.project_id, g.is_global,
                    pu.name AS personal_user_name, g.created_at, ' . AccessService::GROUP_MEMBER_COUNT_SQL . ' AS member_count,
                    EXISTS (SELECT 1 FROM users_groups viewer_ug
                        JOIN users viewer_u ON viewer_u.id = viewer_ug.user_id AND viewer_u.deleted_at IS NULL
                        WHERE viewer_ug.groups_id = g.id AND viewer_ug.user_id = :viewer_id) AS is_current_user_member
             FROM projects_groups pg
             JOIN groups g ON g.id = pg.group_id
             LEFT JOIN users pu ON pu.id = g.personal_user_id AND pu.deleted_at IS NULL
             WHERE pg.project_id = :project_id
               AND g.personal_user_id IS NULL AND (g.is_global = 1 OR g.project_id = pg.project_id)
             ORDER BY g.name'
        );
        $stmt->execute(['project_id' => $projectId, 'viewer_id' => $viewerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addGroup(int $projectId, int $groupId, int $actorId): bool {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects_groups (project_id, group_id)
             SELECT p.id, g.id FROM projects p JOIN groups g ON g.id = :group_id
             ' . AccessService::ACTOR_JOIN . '
             WHERE p.id = :project_id AND p.archived_at IS NULL
               AND g.personal_user_id IS NULL AND (g.is_global = 1 OR g.project_id = p.id)
               AND ' . AccessService::PROJECT_MANAGE_SQL
        );
        $stmt->execute(['project_id' => $projectId, 'group_id' => $groupId, 'access_user' => $actorId]);
        return $stmt->rowCount() === 1;
    }

    public function createGroup(int $projectId, string $name, array $userIds, int $actorId): int {
        if (!Group::isValidName($name)) throw new \InvalidArgumentException('Gruppenname muss 1 bis 100 Zeichen enthalten');
        // Keep the group, memberships, and project link atomic.
        $this->pdo->beginTransaction();
        try {
            $group = $this->pdo->prepare('INSERT INTO groups (name, project_id)
                SELECT :name, p.id FROM projects p ' . AccessService::ACTOR_JOIN . '
                WHERE p.id = :project_id AND p.archived_at IS NULL AND ' . AccessService::PROJECT_MANAGE_SQL);
            $group->execute(['name' => $name, 'project_id' => $projectId, 'access_user' => $actorId]);
            if ($group->rowCount() !== 1) throw new \DomainException('Projekt oder Berechtigung inzwischen geaendert');
            $groupId = (int) $this->pdo->lastInsertId();

            $membership = $this->pdo->prepare(
                'INSERT INTO users_groups (user_id, groups_id)
                 SELECT id, :group_id FROM users WHERE id = :user_id AND deleted_at IS NULL'
            );
            foreach ($userIds as $userId) {
                $membership->execute(['user_id' => $userId, 'group_id' => $groupId]);
                if ($membership->rowCount() !== 1) {
                    throw new \InvalidArgumentException('Benutzer nicht gefunden');
                }
            }

            if (!$this->addGroup($projectId, $groupId, $actorId)) {
                throw new \DomainException('Projekt oder Berechtigung inzwischen geaendert');
            }
            $this->pdo->commit();
            return $groupId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function removeGroup(int $projectId, int $groupId, int $actorId): bool {
        // Remove this project's task assignments before dropping its group allowlist entry.
        $this->pdo->beginTransaction();
        try {
            $tasks = $this->pdo->prepare(
                'DELETE FROM groups_tasks
                 WHERE group_id = :group_id AND task_id IN (
                     SELECT t.id FROM tasks t
                     JOIN containers c ON c.id = t.container_id
                     JOIN projects p ON p.id = c.project_id ' . AccessService::ACTOR_JOIN . '
                     WHERE c.project_id = :project_id AND p.archived_at IS NULL
                       AND ' . AccessService::PROJECT_MANAGE_SQL . '
                 )'
            );
            $tasks->execute(['project_id' => $projectId, 'group_id' => $groupId, 'access_user' => $actorId]);

            $assignment = $this->pdo->prepare(
                'DELETE FROM projects_groups WHERE project_id = :project_id AND group_id = :group_id
                 AND EXISTS (SELECT 1 FROM projects p ' . AccessService::ACTOR_JOIN . '
                     WHERE p.id = projects_groups.project_id AND p.archived_at IS NULL
                       AND ' . AccessService::PROJECT_MANAGE_SQL . ')'
            );
            $assignment->execute(['project_id' => $projectId, 'group_id' => $groupId, 'access_user' => $actorId]);
            $removed = $assignment->rowCount() > 0;
            if (!$removed) {
                $this->pdo->rollBack();
                return false;
            }
            $this->pdo->commit();
            return $removed;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateGroup(int $projectId, int $groupId, string $name, array $userIds, int $actorId): bool {
        if (!Group::isValidName($name) || Group::isReservedName($name) || $userIds === []) {
            throw new \InvalidArgumentException('Gueltiger Gruppenname und mindestens ein Benutzer sind erforderlich');
        }
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT g.id FROM groups g
                JOIN projects p ON p.id = g.project_id ' . AccessService::ACTOR_JOIN . '
                WHERE g.id = :group_id AND p.id = :project_id AND p.archived_at IS NULL
                  AND g.personal_user_id IS NULL AND g.is_global = 0
                  AND ' . AccessService::PROJECT_MANAGE_SQL . ' FOR UPDATE');
            $lock->execute(['group_id' => $groupId, 'project_id' => $projectId, 'access_user' => $actorId]);
            if (!$lock->fetchColumn()) {
                $this->pdo->rollBack();
                return false;
            }
            $rename = $this->pdo->prepare('UPDATE groups g JOIN projects p ON p.id = g.project_id
                ' . AccessService::ACTOR_JOIN . ' SET g.name = :name
                WHERE g.id = :group_id AND p.id = :project_id AND p.archived_at IS NULL
                  AND g.personal_user_id IS NULL AND g.is_global = 0 AND ' . AccessService::PROJECT_MANAGE_SQL);
            $rename->execute(['name' => trim($name), 'group_id' => $groupId, 'project_id' => $projectId, 'access_user' => $actorId]);

            $members = $this->pdo->prepare('SELECT user_id FROM users_groups WHERE groups_id = :group_id');
            $members->execute(['group_id' => $groupId]);
            $oldIds = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
            $group = new Group($this->pdo);
            foreach (array_diff($userIds, $oldIds) as $userId) {
                if (!$group->addUserToGroup($userId, $groupId, $actorId)) {
                    throw new \InvalidArgumentException('Benutzer nicht gefunden');
                }
            }
            // Existing active members must also still be valid, not just newly added users.
            $activeIds = array_map('intval', array_column($group->getUsersInGroup($groupId), 'id'));
            if (array_diff($userIds, $activeIds)) {
                throw new \InvalidArgumentException('Benutzer nicht gefunden');
            }
            foreach (array_diff($oldIds, $userIds) as $userId) {
                if (!$group->removeUserFromGroup($userId, $groupId, $actorId)) {
                    throw new \DomainException('Gruppe oder Berechtigung inzwischen geaendert');
                }
            }
            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    // READ (Einzelnes Project nach ID, nur wenn nicht archiviert)
    public function getById(int $id, ?int $userId = null): ?array {
        $sql = "SELECT p.*, u.name AS creator_name " .
                ($userId === null ? '' : ', ' . $this->permissionColumns()) . "
                FROM projects p
                JOIN users u ON p.created_by = u.id
                " . ($userId === null ? '' : AccessService::ACTOR_JOIN) . "
                WHERE p.id = :id AND " . ($userId === null ? 'p.archived_at IS NULL' :
                    AccessService::PROJECT_READ_STATE_SQL . ' AND ' . AccessService::PROJECT_VIEW_SQL);
        
        $stmt = $this->pdo->prepare($sql);
        $parameters = ['id' => $id];
        if ($userId !== null) $parameters['access_user'] = $userId;
        $stmt->execute($parameters);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    // UPDATE
    public function update(int $id, array $data, int $userId): bool {
        $sql = "UPDATE projects 
                SET name = :name
                WHERE id = :id AND archived_at IS NULL AND EXISTS (
                    SELECT 1 FROM users a WHERE a.id = :access_user AND a.deleted_at IS NULL
                      AND (a.is_admin = 1 OR projects.created_by = a.id))";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'   => $id,
            'access_user' => $userId,
            'name' => $data['name']
        ]);
        return $stmt->rowCount() > 0 || $this->isOwner($id, $userId);
    }

    // Archiving hides the project but preserves its records for future statistics.
    public function archive(int $id, int $archivedBy): bool {
        $sql = "UPDATE projects 
                SET archived_at = NOW(), archived_by = :archived_by 
                WHERE id = :id AND archived_at IS NULL AND EXISTS (
                    SELECT 1 FROM users a WHERE a.id = :access_user AND a.deleted_at IS NULL
                      AND (a.is_admin = 1 OR projects.created_by = a.id))";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'         => $id,
            'access_user' => $archivedBy,
            'archived_by' => $archivedBy
        ]);
        return $stmt->rowCount() === 1;
    }

    public function isAdmin(int $userId): bool {
        return (new AccessService($this->pdo))->isAdmin($userId);
    }

    public function restore(int $id, int $userId): bool {
        // Recheck admin rights in the write; restore neither content nor revoked memberships.
        $stmt = $this->pdo->prepare('UPDATE projects SET archived_at = NULL, archived_by = NULL
            WHERE id = :id AND archived_at IS NOT NULL AND EXISTS (
                SELECT 1 FROM users WHERE id = :user_id AND deleted_at IS NULL AND is_admin = 1
            )');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }
}