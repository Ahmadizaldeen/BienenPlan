<?php

namespace BienenPlan\Models;

use PDO;

class TaskAttachment {
    public function __construct(private PDO $pdo) {}

    public function accessibleTask(int $taskId, int $userId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, p.created_by AS project_owner
             FROM tasks t
             JOIN containers c ON c.id = t.container_id
             JOIN projects p ON p.id = c.project_id
             WHERE t.id = :task_id AND t.deleted_at IS NULL
               AND c.deleted_at IS NULL AND p.archived_at IS NULL
               AND (c.created_by = :container_owner OR p.created_by = :project_owner
                    OR EXISTS (
                        SELECT 1 FROM groups_tasks gt
                        JOIN users_groups ug ON ug.groups_id = gt.group_id
                        WHERE gt.task_id = t.id AND ug.user_id = :member
                    ))'
        );
        $stmt->execute([
            'task_id' => $taskId,
            'container_owner' => $userId,
            'project_owner' => $userId,
            'member' => $userId,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function byTask(int $taskId, int $userId, int $projectOwner): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, task_id, uploaded_by, original_name, mime_type, size_bytes, created_at,
                    (uploaded_by = :uploader OR :owner_id = :project_owner) AS can_delete
             FROM task_attachments
             WHERE task_id = :task_id AND deleted_at IS NULL ORDER BY created_at, id'
        );
        $stmt->execute([
            'task_id' => $taskId,
            'uploader' => $userId,
            'owner_id' => $userId,
            'project_owner' => $projectOwner,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function byId(int $taskId, int $id): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM task_attachments WHERE id = :id AND task_id = :task_id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id, 'task_id' => $taskId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function begin(): void {
        $this->pdo->beginTransaction();
    }

    public function commit(): void {
        $this->pdo->commit();
    }

    public function rollback(): void {
        $this->pdo->rollBack();
    }

    public function add(int $taskId, int $userId, string $name, string $stored, string $mime, int $size): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO task_attachments (task_id, uploaded_by, original_name, stored_name, mime_type, size_bytes)
             VALUES (:task_id, :uploaded_by, :original_name, :stored_name, :mime_type, :size_bytes)'
        );
        $stmt->execute([
            'task_id' => $taskId,
            'uploaded_by' => $userId,
            'original_name' => $name,
            'stored_name' => $stored,
            'mime_type' => $mime,
            'size_bytes' => $size,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function remove(int $taskId, int $id): void {
        $stmt = $this->pdo->prepare(
            'UPDATE task_attachments SET deleted_at = NOW()
             WHERE task_id = :task_id AND id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['task_id' => $taskId, 'id' => $id]);
    }
}
