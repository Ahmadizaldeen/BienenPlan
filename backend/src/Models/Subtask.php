<?php

namespace BienenPlan\Models;

use PDO;
use BienenPlan\Services\AccessService;

class Subtask {
    public function __construct(private PDO $pdo) {}

    public function accessibleTask(int $taskId, int $userId): ?array {
        return (new AccessService($this->pdo))->accessibleTask($userId, $taskId);
    }

    public function access(): AccessService {
        return new AccessService($this->pdo);
    }

    public function byTask(int $taskId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM subtasks WHERE task_id = :task_id AND deleted_at IS NULL ORDER BY id');
        $stmt->execute(['task_id' => $taskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function byId(int $taskId, int $id): ?array {
        // Both IDs are required so nested URLs cannot mutate another task's subtask.
        $stmt = $this->pdo->prepare('SELECT * FROM subtasks WHERE task_id = :task_id AND id = :id AND deleted_at IS NULL');
        $stmt->execute(['task_id' => $taskId, 'id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(int $taskId, int $userId, string $title): int {
        $stmt = $this->pdo->prepare('INSERT INTO subtasks (task_id, created_by, title, completed)
            SELECT :task_id, :user_id, :title, 0 WHERE EXISTS (' .
            AccessService::taskWriteQuery(AccessService::PROJECT_MANAGE_SQL . ' OR t.created_by = a.id') . ')');
        $stmt->execute(['task_id' => $taskId, 'user_id' => $userId, 'title' => $title,
            'write_task' => $taskId, 'access_user' => $userId]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Aufgabe oder Berechtigung inzwischen geaendert');
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $taskId, int $id, array $changes, int $userId): bool {
        // Update only submitted fields: checkbox requests must not overwrite titles.
        $fields = [];
        $parameters = ['task_id' => $taskId, 'id' => $id, 'write_task' => $taskId, 'access_user' => $userId];
        foreach (['title', 'completed'] as $field) {
            if (array_key_exists($field, $changes)) {
                $fields[] = "$field = :$field";
                $parameters[$field] = $field === 'completed' ? (int) $changes[$field] : $changes[$field];
            }
        }
        $rule = array_key_exists('title', $changes)
            ? AccessService::PROJECT_MANAGE_SQL . ' OR subtasks.created_by = a.id' : '1 = 1';
        $stmt = $this->pdo->prepare('UPDATE subtasks SET ' . implode(', ', $fields) .
            ' WHERE task_id = :task_id AND id = :id AND deleted_at IS NULL
              AND EXISTS (' . AccessService::taskWriteQuery($rule) . ')');
        $stmt->execute($parameters);
        if ($stmt->rowCount() > 0) return true;
        $task = $this->accessibleTask($taskId, $userId);
        $item = $this->byId($taskId, $id);
        return $task !== null && $item !== null && (bool) $task['can_write']
            && (!array_key_exists('title', $changes) || $this->access()->canEditSubtask($task, $item, $userId));
    }

    public function delete(int $taskId, int $id, int $userId): bool {
        $stmt = $this->pdo->prepare('UPDATE subtasks SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :user_id
            WHERE task_id = :task_id AND id = :id AND deleted_at IS NULL AND EXISTS (' .
            AccessService::taskWriteQuery(AccessService::PROJECT_MANAGE_SQL .
                ' OR subtasks.created_by = a.id OR t.created_by = a.id') . ')');
        $stmt->execute(['task_id' => $taskId, 'id' => $id, 'user_id' => $userId,
            'write_task' => $taskId, 'access_user' => $userId]);
        return $stmt->rowCount() === 1;
    }
}