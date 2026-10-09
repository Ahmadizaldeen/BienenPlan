<?php

namespace BienenPlan\Models;

use PDO;
use BienenPlan\Services\AccessService;

class TaskAttachment {
    public function __construct(private PDO $pdo) {}

    public function accessibleTask(int $taskId, int $userId): ?array {
        return (new AccessService($this->pdo))->accessibleTask($userId, $taskId);
    }

    public function access(): AccessService {
        return new AccessService($this->pdo);
    }

    public function byTask(int $taskId, int $userId, int $projectOwner): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, task_id, uploaded_by, original_name, mime_type, size_bytes, created_at
             FROM task_attachments
             WHERE task_id = :task_id AND deleted_at IS NULL ORDER BY created_at, id'
        );
        $stmt->execute(['task_id' => $taskId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $access = $this->access();
        $task = $access->accessibleTask($userId, $taskId);
        foreach ($items as &$item) {
            $item['can_delete'] = $task !== null && $access->canDeleteAttachment($task, $item, $userId);
        }
        return $items;
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
             SELECT :task_id, :uploaded_by, :original_name, :stored_name, :mime_type, :size_bytes
             WHERE EXISTS (' . AccessService::taskWriteQuery() . ')'
        );
        $stmt->execute([
            'task_id' => $taskId,
            'uploaded_by' => $userId,
            'original_name' => $name,
            'stored_name' => $stored,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'write_task' => $taskId,
            'access_user' => $userId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Aufgabe oder Berechtigung inzwischen geaendert');
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function remove(int $taskId, int $id, int $userId): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE task_attachments SET deleted_at = NOW()
             WHERE task_id = :task_id AND id = :id AND deleted_at IS NULL AND EXISTS (' .
             AccessService::taskWriteQuery(AccessService::PROJECT_MANAGE_SQL .
                 ' OR task_attachments.uploaded_by = a.id') . ')'
        );
        $stmt->execute(['task_id' => $taskId, 'id' => $id, 'write_task' => $taskId, 'access_user' => $userId]);
        return $stmt->rowCount() === 1;
    }
}
