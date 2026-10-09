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
                VALUES (:name, :created_by)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'name'        => $data['name'],
            'created_by'  => $data['created_by']
        ]);

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

    public function getGroups(int $projectId): array {
        $stmt = $this->pdo->prepare(
            'SELECT g.id, g.name, g.personal_user_id, g.project_id, g.is_global,
                    pu.name AS personal_user_name, g.created_at
             FROM projects_groups pg
             JOIN groups g ON g.id = pg.group_id
             LEFT JOIN users pu ON pu.id = g.personal_user_id AND pu.deleted_at IS NULL
             WHERE pg.project_id = :project_id
               AND g.personal_user_id IS NULL AND (g.is_global = 1 OR g.project_id = pg.project_id)
             ORDER BY g.name'
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addGroup(int $projectId, int $groupId): bool {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects_groups (project_id, group_id)
             SELECT p.id, g.id FROM projects p JOIN groups g ON g.id = :group_id
             WHERE p.id = :project_id AND p.archived_at IS NULL
               AND g.personal_user_id IS NULL AND (g.is_global = 1 OR g.project_id = p.id)'
        );
        $stmt->execute(['project_id' => $projectId, 'group_id' => $groupId]);
        return $stmt->rowCount() === 1;
    }

    public function createGroup(int $projectId, string $name, array $userIds): int {
        // Keep the group, memberships, and project link atomic.
        $this->pdo->beginTransaction();
        try {
            $group = $this->pdo->prepare('INSERT INTO groups (name, project_id) VALUES (:name, :project_id)');
            $group->execute(['name' => $name, 'project_id' => $projectId]);
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

            $assignment = $this->pdo->prepare(
                'INSERT INTO projects_groups (project_id, group_id) VALUES (:project_id, :group_id)'
            );
            $assignment->execute(['project_id' => $projectId, 'group_id' => $groupId]);
            $this->pdo->commit();
            return $groupId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function removeGroup(int $projectId, int $groupId): bool {
        // Remove this project's task assignments before dropping its group allowlist entry.
        $this->pdo->beginTransaction();
        try {
            $tasks = $this->pdo->prepare(
                'DELETE FROM groups_tasks
                 WHERE group_id = :group_id AND task_id IN (
                     SELECT t.id FROM tasks t
                     JOIN containers c ON c.id = t.container_id
                     WHERE c.project_id = :project_id
                 )'
            );
            $tasks->execute(['project_id' => $projectId, 'group_id' => $groupId]);

            $assignment = $this->pdo->prepare(
                'DELETE FROM projects_groups WHERE project_id = :project_id AND group_id = :group_id'
            );
            $assignment->execute(['project_id' => $projectId, 'group_id' => $groupId]);
            $removed = $assignment->rowCount() > 0;
            $this->pdo->commit();
            return $removed;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
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
    public function update(int $id, array $data): bool {
        $sql = "UPDATE projects 
                SET name = :name
                WHERE id = :id AND archived_at IS NULL";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id'   => $id,
            'name' => $data['name']
        ]);
    }

    // Archiving hides the project but preserves its records for future statistics.
    public function archive(int $id, int $archivedBy): bool {
        $sql = "UPDATE projects 
                SET archived_at = NOW(), archived_by = :archived_by 
                WHERE id = :id AND archived_at IS NULL";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id'         => $id,
            'archived_by' => $archivedBy
        ]);
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