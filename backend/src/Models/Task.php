<?php

namespace BienenPlan\Models;

use PDO;
use BienenPlan\Services\AccessService;

class Task {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // CREATE
    public function create(array $data): int {
        $sql = "INSERT INTO tasks (container_id, created_by, title, description, status, deadline)
                VALUES (:container_id, :created_by, :title, :description, :status, :deadline)";
        
        $this->pdo->beginTransaction();
        try {
            // Serialize with Container::delete(), which locks the same parent row.
            $lock = $this->pdo->prepare('SELECT id FROM containers WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $data['container_id']]);
            if (!(new AccessService($this->pdo))->canCreateTask((int) $data['created_by'], (int) $data['container_id'])) {
                throw new \DomainException('Container nicht gefunden');
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'container_id' => $data['container_id'],
                'created_by'   => $data['created_by'],
                'title'        => $data['title'],
                'description'  => $data['description'] ?? null,
                'status'       => $data['status'] ?? 'open',
                'deadline'     => $data['deadline'] ?? null
            ]);
            $taskId = (int) $this->pdo->lastInsertId();
            // Keep the default assignment atomic without adding a project membership.
            $assignment = $this->pdo->prepare(
                'INSERT INTO groups_tasks (task_id, group_id)
                 SELECT :task_id, g.id FROM groups g
                 JOIN users_groups ug ON ug.groups_id = g.id AND ug.user_id = g.personal_user_id
                 WHERE g.personal_user_id = :creator_id'
            );
            $assignment->execute(['task_id' => $taskId, 'creator_id' => $data['created_by']]);
            if ($assignment->rowCount() !== 1) {
                throw new \RuntimeException('Persoenliche Gruppe des Task-Erstellers fehlt oder ist ungueltig');
            }
            $this->pdo->commit();
            return $taskId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    // READ (Alle nicht-gelöschten Tasks aller Benutzer)
    public function getAll(): array {
        $sql = "SELECT t.*, c.title AS container_title, u.name AS creator_name 
                FROM tasks t
                JOIN containers c ON t.container_id = c.id
                LEFT JOIN users u ON t.created_by = u.id
                WHERE t.deleted_at IS NULL
                ORDER BY t.created_at DESC";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Admins/project owners see all tasks; member visibility is separate from assignment metadata.
    public function getAllByUser(int $userId, ?int $projectId = null): array {
    $assignedGroup = TaskAccess::ASSIGNED_GROUP_SQL;
    $visibility = TaskAccess::VISIBILITY_SQL;
    $sql = "SELECT 
                t.*, 
                c.title AS container_title, 
                p.id AS project_id,
                p.name AS project_name,
                p.archived_at AS project_archived_at,
                u.name AS creator_name,
                " . AccessService::creatorAccessSql() . " AS creator_has_project_access,
                " . AccessService::TASK_EDIT_SQL . " AS can_edit,
                " . AccessService::TASK_DELETE_SQL . " AS can_delete,
                " . AccessService::TASK_GROUP_MANAGE_SQL . " AS can_manage_groups,
                (p.archived_at IS NULL) AS can_change_status,
                GROUP_CONCAT(DISTINCT CASE WHEN $assignedGroup THEN gt.group_id END ORDER BY gt.group_id) AS group_ids,
                GROUP_CONCAT(DISTINCT CASE WHEN $assignedGroup THEN g.name END ORDER BY g.name SEPARATOR ', ') AS group_names
            FROM tasks t
            JOIN containers c ON t.container_id = c.id
            JOIN projects p ON c.project_id = p.id
            " . AccessService::ACTOR_JOIN . "
            LEFT JOIN users u ON t.created_by = u.id
            LEFT JOIN groups_tasks gt ON gt.task_id = t.id
                        LEFT JOIN projects_groups pg ON pg.project_id = p.id AND pg.group_id = gt.group_id
            LEFT JOIN groups g ON g.id = gt.group_id
            WHERE t.deleted_at IS NULL
              AND c.deleted_at IS NULL AND " . ($projectId === null ? 'p.archived_at IS NULL' :
                AccessService::PROJECT_READ_STATE_SQL . ' AND p.id = :project_id') . "
              AND $visibility
            GROUP BY 
                t.id, 
                c.title, 
                p.id,
                p.name,
                u.name
            ORDER BY t.created_at DESC";
    
    $stmt = $this->pdo->prepare($sql);
    $parameters = ['access_user' => $userId];
    if ($projectId !== null) $parameters['project_id'] = $projectId;
    $stmt->execute($parameters);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

    // READ (Einzelne Task nach ID, inkl. Projekt- und Gruppen-Zuordnung)
    public function getById(int $id, ?int $userId = null): ?array {
        $assignedGroup = TaskAccess::ASSIGNED_GROUP_SQL;
        $permissions = $userId === null ? '' : AccessService::TASK_EDIT_SQL . ' AS can_edit, ' .
            AccessService::TASK_DELETE_SQL . ' AS can_delete, ' .
            AccessService::TASK_GROUP_MANAGE_SQL . ' AS can_manage_groups, (p.archived_at IS NULL) AS can_change_status, ';
        $sql = "SELECT 
                    t.*, 
                    c.title AS container_title, 
                    p.id AS project_id,
                    p.name AS project_name,
                    p.archived_at AS project_archived_at,
                    p.created_by AS project_created_by,
                    u.name AS creator_name,
                    " . AccessService::creatorAccessSql() . " AS creator_has_project_access,
                    $permissions
                    GROUP_CONCAT(DISTINCT CASE WHEN $assignedGroup THEN gt.group_id END ORDER BY gt.group_id) AS group_ids,
                    GROUP_CONCAT(DISTINCT CASE WHEN $assignedGroup THEN g.name END ORDER BY g.name SEPARATOR ', ') AS group_names
                FROM tasks t
                JOIN containers c ON t.container_id = c.id
                JOIN projects p ON c.project_id = p.id
                LEFT JOIN users u ON t.created_by = u.id
                " . ($userId === null ? '' : AccessService::ACTOR_JOIN) . "
                LEFT JOIN groups_tasks gt ON gt.task_id = t.id
                LEFT JOIN projects_groups pg ON pg.project_id = p.id AND pg.group_id = gt.group_id
                LEFT JOIN groups g ON g.id = gt.group_id
                WHERE t.id = :id AND t.deleted_at IS NULL
                  AND c.deleted_at IS NULL AND " . ($userId === null ? 'p.archived_at IS NULL' : AccessService::PROJECT_READ_STATE_SQL) . "
                  " . ($userId === null ? '' : 'AND ' . AccessService::TASK_VIEW_SQL) . "
                GROUP BY t.id, c.title, p.id, p.name, p.created_by, u.name";

        $stmt = $this->pdo->prepare($sql);
        $parameters = ['id' => $id];
        if ($userId !== null) $parameters['access_user'] = $userId;
        $stmt->execute($parameters);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function isVisibleToUser(int $taskId, int $userId): bool {
        return (new AccessService($this->pdo))->canViewTask($userId, $taskId);
    }

    public function canEdit(int $taskId, int $userId): bool {
        return (new AccessService($this->pdo))->canEditTask($userId, $taskId);
    }

    public function canDelete(int $taskId, int $userId): bool {
        return (new AccessService($this->pdo))->canDeleteTask($userId, $taskId);
    }

    public function canAccessContainer(int $containerId, int $userId): bool {
        return (new AccessService($this->pdo))->canCreateTask($userId, $containerId);
    }

    public function canChangeStatus(int $taskId, int $userId): bool {
        return (new AccessService($this->pdo))->canChangeTaskStatus($userId, $taskId);
    }

    // UPDATE nur Status
    public function updateStatus(int $id, string $status): bool {
        $sql = "UPDATE tasks SET status = :status WHERE id = :id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id, 'status' => $status]);
    }

    // UPDATE
    public function update(int $id, array $data): bool {
        $sql = "UPDATE tasks 
                SET title = :title, 
                    description = :description, 
                    status = :status, 
                    deadline = :deadline
                WHERE id = :id AND deleted_at IS NULL";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id'          => $id,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'status'      => $data['status'] ?? 'open',
            'deadline'    => $data['deadline'] ?? null
        ]);
    }

    // DELETE (Soft-Delete)
    public function delete(int $id, int $deletedBy): bool {
        $sql = "UPDATE tasks t
                JOIN containers c ON c.id = t.container_id
                JOIN projects p ON p.id = c.project_id
                " . AccessService::ACTOR_JOIN . "
                SET t.deleted_at = NOW(), t.deleted_by = :deleted_by
                WHERE t.id = :id
                  AND t.deleted_at IS NULL
                  AND c.deleted_at IS NULL
                  AND p.archived_at IS NULL
                  AND " . AccessService::TASK_DELETE_SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'         => $id,
            'deleted_by' => $deletedBy,
            'access_user' => $deletedBy,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function move(int $id, int $containerId, int $userId): void {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM containers WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $containerId]);
            $access = new AccessService($this->pdo);
            if (!$access->canManageTaskGroups($userId, $id) || !$access->canCreateTask($userId, $containerId)) {
                throw new \DomainException('Keine Berechtigung oder Container nicht gefunden');
            }
            // Keep project-scoped assignments valid by rejecting cross-project moves in SQL.
            $stmt = $this->pdo->prepare('UPDATE tasks SET container_id = :target
                WHERE id = :id AND container_id IN (
                    SELECT source.id FROM containers source JOIN containers destination
                      ON destination.project_id = source.project_id
                    WHERE destination.id = :destination AND destination.deleted_at IS NULL
                ) AND deleted_at IS NULL');
            $stmt->execute(['target' => $containerId, 'id' => $id, 'destination' => $containerId]);
            $current = $this->pdo->prepare('SELECT container_id FROM tasks WHERE id = :id');
            $current->execute(['id' => $id]);
            if ((int) $current->fetchColumn() !== $containerId) {
                throw new \DomainException('Tasks koennen nur innerhalb desselben Projekts verschoben werden');
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }
}