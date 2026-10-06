<?php

namespace BienenPlan\Models;

use PDO;

class Subtask {
    public function __construct(private PDO $pdo) {}

    public function accessibleTask(int $taskId, int $userId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, c.created_by AS container_owner, p.created_by AS project_owner
             FROM tasks t
             JOIN containers c ON c.id = t.container_id
             JOIN projects p ON p.id = c.project_id
             WHERE t.id = :task_id AND t.deleted_at IS NULL
               AND c.deleted_at IS NULL AND p.archived_at IS NULL
               AND (c.created_by = :container_owner OR p.created_by = :project_owner
                    OR EXISTS (SELECT 1 FROM groups_tasks gt
                        JOIN users_groups ug ON ug.groups_id = gt.group_id
                        WHERE gt.task_id = t.id AND ug.user_id = :member))'
        );
        $stmt->execute([
            'task_id' => $taskId, 'container_owner' => $userId,
            'project_owner' => $userId, 'member' => $userId,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
        $stmt = $this->pdo->prepare('INSERT INTO subtasks (task_id, created_by, title, completed) VALUES (:task_id, :user_id, :title, 0)');
        $stmt->execute(['task_id' => $taskId, 'user_id' => $userId, 'title' => $title]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $taskId, int $id, array $changes): void {
        // Update only submitted fields: checkbox requests must not overwrite titles.
        $fields = [];
        $parameters = ['task_id' => $taskId, 'id' => $id];
        foreach (['title', 'completed'] as $field) {
            if (array_key_exists($field, $changes)) {
                $fields[] = "$field = :$field";
                $parameters[$field] = $field === 'completed' ? (int) $changes[$field] : $changes[$field];
            }
        }
        $stmt = $this->pdo->prepare('UPDATE subtasks SET ' . implode(', ', $fields) . ' WHERE task_id = :task_id AND id = :id AND deleted_at IS NULL');
        $stmt->execute($parameters);
    }

    public function delete(int $taskId, int $id, int $userId): void {
        $stmt = $this->pdo->prepare('UPDATE subtasks SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :user_id WHERE task_id = :task_id AND id = :id AND deleted_at IS NULL');
        $stmt->execute(['task_id' => $taskId, 'id' => $id, 'user_id' => $userId]);
    }
}