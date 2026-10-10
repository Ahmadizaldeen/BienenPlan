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
                SELECT c.id, a.id, :title, :description, :status, :deadline
                FROM containers c JOIN projects p ON p.id = c.project_id
                " . AccessService::ACTOR_JOIN . "
                WHERE c.id = :container_id AND c.deleted_at IS NULL
                  AND p.archived_at IS NULL AND " . AccessService::PROJECT_VIEW_SQL;
        
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
                'access_user'  => $data['created_by'],
                'title'        => $data['title'],
                'description'  => $data['description'] ?? null,
                'status'       => $data['status'] ?? 'open',
                'deadline'     => $data['deadline'] ?? null
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new \DomainException('Container nicht gefunden');
            }
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
                " . AccessService::TASK_EDIT_SQL . " AS can_create_subtasks,
                " . AccessService::TASK_TITLE_DEADLINE_EDIT_SQL . " AS can_edit_title_deadline,
                " . AccessService::TASK_DELETE_SQL . " AS can_delete,
                " . AccessService::TASK_GROUP_MANAGE_SQL . " AS can_manage_groups,
                " . AccessService::TASK_LOCAL_GROUP_MANAGE_SQL . " AS can_manage_local_groups,
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
            AccessService::TASK_EDIT_SQL . ' AS can_create_subtasks, ' .
            AccessService::TASK_TITLE_DEADLINE_EDIT_SQL . ' AS can_edit_title_deadline, ' .
            AccessService::TASK_DELETE_SQL . ' AS can_delete, ' .
            AccessService::TASK_GROUP_MANAGE_SQL . ' AS can_manage_groups, (p.archived_at IS NULL) AS can_change_status, ';
        if ($userId !== null) $permissions .= AccessService::TASK_LOCAL_GROUP_MANAGE_SQL . ' AS can_manage_local_groups, ';
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

    public function canEditTitleDeadline(int $taskId, int $userId): bool {
        return (new AccessService($this->pdo))->canEditTaskTitleDeadline($userId, $taskId);
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
    public function updateStatus(int $id, string $status, int $userId): bool {
        // Recheck policy in the mutation, not only in the earlier controller read.
        $sql = "UPDATE tasks t
                JOIN containers c ON c.id = t.container_id
                JOIN projects p ON p.id = c.project_id
                " . AccessService::ACTOR_JOIN . "
                SET t.status = :status
                WHERE t.id = :id AND t.deleted_at IS NULL AND c.deleted_at IS NULL
                  AND p.archived_at IS NULL AND " . AccessService::TASK_VIEW_SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'status' => $status, 'access_user' => $userId]);
        // MySQL reports zero changed rows for an authorized, unchanged value too.
        return $stmt->rowCount() > 0 || $this->canChangeStatus($id, $userId);
    }

    // UPDATE
    public function update(int $id, array $data, int $userId): bool {
        if (!array_diff(array_keys($data), ['title', 'deadline'])) {
            return $this->updateTitleDeadline($id, $data, $userId);
        }
        $sql = "UPDATE tasks t
                JOIN containers c ON c.id = t.container_id
                JOIN projects p ON p.id = c.project_id
                " . AccessService::ACTOR_JOIN . "
                SET t.title = :title,
                    t.description = :description,
                    t.status = :status,
                    t.deadline = :deadline
                WHERE t.id = :id AND t.deleted_at IS NULL AND c.deleted_at IS NULL
                  AND " . AccessService::TASK_EDIT_SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'          => $id,
            'access_user' => $userId,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'status'      => $data['status'] ?? 'open',
            'deadline'    => $data['deadline'] ?? null
        ]);
        return $stmt->rowCount() > 0 || $this->canEdit($id, $userId);
    }

    private function updateTitleDeadline(int $id, array $data, int $userId): bool {
        if (!is_string($data['title'] ?? null) || trim($data['title']) === '') {
            throw new \InvalidArgumentException('title darf nicht leer sein');
        }
        $sql = 'UPDATE tasks t
                JOIN containers c ON c.id = t.container_id
                JOIN projects p ON p.id = c.project_id
                ' . AccessService::ACTOR_JOIN . '
                SET t.title = :title' . (array_key_exists('deadline', $data) ? ', t.deadline = :deadline' : '') . '
                WHERE t.id = :id AND t.deleted_at IS NULL AND c.deleted_at IS NULL
                  AND ' . AccessService::TASK_TITLE_DEADLINE_EDIT_SQL;
        $parameters = ['id' => $id, 'access_user' => $userId, 'title' => $data['title']];
        if (array_key_exists('deadline', $data)) $parameters['deadline'] = $data['deadline'];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);
        return $stmt->rowCount() > 0 || $this->canEditTitleDeadline($id, $userId);
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
            $stmt = $this->pdo->prepare('UPDATE tasks t JOIN containers c ON c.id = t.container_id
                JOIN projects p ON p.id = c.project_id ' . AccessService::ACTOR_JOIN . '
                JOIN containers destination ON destination.id = :destination AND destination.project_id = p.id
                SET t.container_id = destination.id
                WHERE t.id = :id AND t.deleted_at IS NULL AND c.deleted_at IS NULL
                  AND destination.deleted_at IS NULL AND ' . AccessService::TASK_GROUP_MANAGE_SQL);
            $stmt->execute(['id' => $id, 'destination' => $containerId, 'access_user' => $userId]);
            $current = $this->pdo->prepare('SELECT t.container_id FROM tasks t
                JOIN containers c ON c.id = t.container_id JOIN projects p ON p.id = c.project_id
                ' . AccessService::ACTOR_JOIN . '
                WHERE t.id = :id AND t.deleted_at IS NULL AND c.deleted_at IS NULL
                  AND ' . AccessService::TASK_GROUP_MANAGE_SQL);
            $current->execute(['id' => $id, 'access_user' => $userId]);
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