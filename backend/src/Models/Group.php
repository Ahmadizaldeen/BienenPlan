<?php

// Model for managing groups and their relationships with users and tasks
// Provides methods to create, read, update, and delete groups, as well as manage their relationships with users and tasks.

// getAllGroups(): array
// createGroup(string $name): bool
// addUserToGroup(int $userId, int $groupId): bool
// assignGroup(int $taskId, int $groupId): bool
// getGroupsForTask(int $taskId): array
// removeGroup(int $taskId, int $groupId): bool

// getUsersInGroup(int $groupId): array
// getGroupsForUser(int $userId): array

namespace BienenPlan\Models;

use PDO;

class Group
{
    private PDO $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    //GET all groups
    public function getAllGroups(): array
    {
        $sql = "SELECT id, name, created_at FROM groups ORDER BY name";
        $statement = $this->pdo->prepare($sql);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createGroup(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO groups (name) VALUES (:name)"
        );
        $stmt->execute([
            'name' => $name
        ]);

        return $stmt->rowCount() > 0;
    }
    // GET All Groups for a specific task
    public function getGroupsForTask(int $taskId): array
    {

        $sql = "SELECT g.id, g.name, g.created_at
                FROM groups g
                JOIN groups_tasks gt ON gt.group_id = g.id
                JOIN tasks t ON t.id = gt.task_id
                WHERE gt.task_id = :task_id
                  AND t.deleted_at IS NULL
                ORDER BY g.name";

        $statement = $this->pdo->prepare($sql);
        $statement->execute(['task_id' => $taskId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    //POST: Add a user to a group 
    public function addUserToGroup(int $userId, int $groupId): bool # TODO : handle duplicate (Vergleich mit getGroupsForTask)
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users_groups (user_id, groups_id) VALUES (:user_id, :groups_id)"
        );
        $stmt->execute([
            'user_id' => $userId,
            'groups_id' => $groupId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function assignGroup(int $taskId, int $groupId): bool
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO groups_tasks (task_id, group_id) VALUES (:task_id, :group_id)"
        );

        return $statement->execute([
            'task_id' => $taskId,
            'group_id' => $groupId
        ]);
    }

    public function removeGroup(int $taskId, int $groupId): bool
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM groups_tasks WHERE task_id = :task_id AND group_id = :group_id"
        );
        $statement->execute([
            'task_id' => $taskId,
            'group_id' => $groupId
        ]);

        return $statement->rowCount() > 0;
    }

    // GET all users for a specific group
    public function getUsersInGroup(int $groupId): array
    {
        $sql = "SELECT u.id, u.name, u.email, u.created_at
                FROM users u
                JOIN users_groups ug ON ug.user_id = u.id
                JOIN groups g ON g.id = ug.groups_id
                WHERE ug.groups_id = :group_id
                ORDER BY u.name";

        $statement = $this->pdo->prepare($sql);
        $statement->execute(['group_id' => $groupId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    // GET all groups for a specific user
    public function getGroupsForUser(int $userId): array
    {
        $sql = "SELECT g.id, g.name, g.created_at
                FROM groups g
                JOIN users_groups ug ON ug.groups_id = g.id
                JOIN users u ON u.id = ug.user_id
                WHERE ug.user_id = :user_id
                ORDER BY g.name";

        $statement = $this->pdo->prepare($sql);
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
