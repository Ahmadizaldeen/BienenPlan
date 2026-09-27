<?php

// Model for managing groups and their relationships with users and tasks
// Provides methods to create, read, update, and delete groups, as well as manage their relationships with users and tasks.

// getAllGroups(): array
// createGroup(string $name, array $userIds = []): int
// addUserToGroup(int $userId, int $groupId): bool
// assignGroup(int $taskId, int $groupId): bool
// getGroupsForTask(int $taskId): array
// removeGroup(int $taskId, int $groupId): bool

// getUsersInGroup(int $groupId): array
// getGroupsForUser(int $userId): array
// findGroupById(int $groupId): array|false

// Persönliche Gruppen ("Personal user {id}"):
// isReservedName(string $groupName): bool
// parsePersonalUserId(string $groupName): ?int
// resolvePersonalUserId(array $group): ?int
// Listen liefern zusätzlich personal_user_name (per JOIN, siehe PERSONAL_USER_JOIN);
// vollständige Benutzerdaten werden über das User-Model geladen.

namespace BienenPlan\Models;

use PDO;

class Group
{
    // Gemeinsame Spalten + JOIN für alle Gruppen-Listen, damit das Frontend den
    // Benutzernamen persönlicher Gruppen ohne Zusatz-Request pro Gruppe erhält.
    // Gleiche Regel wie resolvePersonalUserId(): FK vor Namens-Fallback ("Personal user {id}", 14 Zeichen Präfix).
    private const GROUP_COLUMNS = "g.id, g.name, g.personal_user_id, pu.name AS personal_user_name, g.created_at";
    private const PERSONAL_USER_JOIN = "LEFT JOIN users pu
                ON pu.deleted_at IS NULL
               AND pu.id = COALESCE(
                    g.personal_user_id,
                    CASE WHEN g.name REGEXP '^Personal user [1-9][0-9]*$'
                         THEN CAST(SUBSTRING(g.name, 15) AS UNSIGNED) END
               )";

    private PDO $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    //GET all groups
    public function getAllGroups(): array
    {
        $sql = "SELECT " . self::GROUP_COLUMNS . "
                FROM groups g
                " . self::PERSONAL_USER_JOIN . "
                ORDER BY g.name";
        $statement = $this->pdo->prepare($sql);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createGroup(string $name, array $userIds = []): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO groups (name) VALUES (:name)"
            );
            $stmt->execute(['name' => $name]);
            $groupId = (int) $this->pdo->lastInsertId();

            if ($userIds !== []) {
                $membership = $this->pdo->prepare(
                    "INSERT INTO users_groups (user_id, groups_id) VALUES (:user_id, :group_id)"
                );
                foreach ($userIds as $userId) {
                    $membership->execute([
                        'user_id' => $userId,
                        'group_id' => $groupId,
                    ]);
                }
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
    // GET All Groups for a specific task
    public function getGroupsForTask(int $taskId): array
    {

        $sql = "SELECT " . self::GROUP_COLUMNS . "
                FROM groups g
                JOIN groups_tasks gt ON gt.group_id = g.id
                JOIN tasks t ON t.id = gt.task_id
                " . self::PERSONAL_USER_JOIN . "
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
    // Der frühere JOIN auf `groups` war überflüssig: groups_id liegt bereits in users_groups.
    public function getUsersInGroup(int $groupId): array
    {
        $sql = "SELECT u.id, u.name, u.email, u.created_at
                FROM users u
                JOIN users_groups ug ON ug.user_id = u.id
                WHERE ug.groups_id = :group_id
                ORDER BY u.name";

        $statement = $this->pdo->prepare($sql);
        $statement->execute(['group_id' => $groupId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    // Prüft, ob ein Name das für persönliche Gruppen reservierte Muster "Personal user ..." nutzt.
    public static function isReservedName(string $groupName): bool
    {
        return preg_match('/^\s*Personal user\b/i', $groupName) === 1;
    }

    // Extrahiert die User-ID aus dem Namen "Personal user {id}" (ohne führende Nullen).
    public static function parsePersonalUserId(string $groupName): ?int
    {
        if (preg_match('/^Personal user ([1-9]\d*)$/i', trim($groupName), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    // Liefert die User-ID einer persönlichen Gruppe oder null bei Team-Gruppen.
    // Quelle der Wahrheit ist die FK-Spalte personal_user_id; der Name dient nur als
    // Fallback für Altdaten. Einzige Stelle dieser Logik (vorher doppelt in Controller/Model).
    public static function resolvePersonalUserId(array $group): ?int
    {
        if (isset($group['personal_user_id'])) {
            return (int) $group['personal_user_id'];
        }

        return self::parsePersonalUserId((string) ($group['name'] ?? ''));
    }

    public function findGroupById(int $groupId): array|false
    {
        $statement = $this->pdo->prepare(
            "SELECT id, name, personal_user_id, created_at FROM groups WHERE id = :group_id"
        );
        $statement->execute(['group_id' => $groupId]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    // GET all groups for a specific user
    // JOIN auf `users` (Filter) entfernt; Spalten/JOIN wie bei den anderen Gruppen-Listen.
    public function getGroupsForUser(int $userId): array
    {
        $sql = "SELECT " . self::GROUP_COLUMNS . "
                FROM groups g
                JOIN users_groups ug ON ug.groups_id = g.id
                " . self::PERSONAL_USER_JOIN . "
                WHERE ug.user_id = :user_id
                ORDER BY g.name";

        $statement = $this->pdo->prepare($sql);
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
