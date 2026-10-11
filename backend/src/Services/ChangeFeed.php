<?php

namespace BienenPlan\Services;

use BienenPlan\Models\TaskAccess;
use PDO;

final class ChangeFeed {
    private const SCOPES = [
        'users', 'projects', 'containers', 'tasks', 'groups', 'subtasks',
        'task_attachments', 'users_groups', 'projects_groups', 'groups_tasks',
    ];

    public function __construct(private PDO $db, private string $secret) {
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET is required for change cursors');
        }
    }

    private function encode(array $payload): string {
        $data = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return $data . '.' . hash_hmac('sha256', $data, $this->secret);
    }

    private function decode(string $cursor, int $userId): array {
        if (strlen($cursor) > 4096 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cursor, $matches)
            || !hash_equals(hash_hmac('sha256', $matches[1], $this->secret), $matches[2])) {
            throw new \InvalidArgumentException('Ungueltiger Aenderungs-Cursor');
        }
        $json = base64_decode(strtr($matches[1], '-_', '+/'), true);
        try {
            $payload = json_decode($json === false ? '' : $json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Ungueltiger Aenderungs-Cursor', 0, $exception);
        }
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1 || ($payload['user'] ?? null) !== $userId
            || !is_array($payload['scopes'] ?? null) || array_keys($payload['scopes']) !== self::SCOPES) {
            throw new \InvalidArgumentException('Ungueltiger Aenderungs-Cursor');
        }
        foreach ($payload['scopes'] as $hash) {
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new \InvalidArgumentException('Ungueltiger Aenderungs-Cursor');
            }
        }
        return $payload['scopes'];
    }

    private function fingerprint(string $sql, int $userId): string {
        $statement = $this->db->prepare($sql);
        $statement->execute(['access_user' => $userId]);
        $hash = hash_init('sha256', HASH_HMAC, $this->secret);
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
        }
        return hash_final($hash);
    }

    private function queries(): array {
        $actor = AccessService::ACTOR_JOIN;
        $projectView = AccessService::PROJECT_VIEW_SQL;
        $projectState = AccessService::PROJECT_READ_STATE_SQL;
        $taskView = AccessService::TASK_VIEW_SQL;
        $groupView = AccessService::GROUP_VIEW_SQL;
        $taskFrom = "JOIN tasks t ON t.id = q.task_id
            JOIN containers c ON c.id = t.container_id JOIN projects p ON p.id = c.project_id $actor";
        $taskWhere = "t.deleted_at IS NULL AND c.deleted_at IS NULL AND $projectState AND $taskView";
        $revision = static fn(string $table, string $key): string =>
            "LEFT JOIN change_stamps s ON s.entity = '$table' AND s.record_key = CAST($key AS CHAR)";

        return [
            // Hash only public names and the actor's own profile, never password hashes.
            'users' => "SELECT u.id, u.name,
                CASE WHEN u.id = a.id THEN u.email END AS email,
                CASE WHEN u.id = a.id THEN u.picture END AS picture,
                CASE WHEN u.id = a.id THEN u.is_admin END AS is_admin
                FROM users u $actor WHERE u.deleted_at IS NULL ORDER BY u.id",
            'projects' => "SELECT p.*, s.revision FROM projects p $actor
                " . $revision('projects', 'p.id') . "
                WHERE $projectState AND $projectView ORDER BY p.id",
            'containers' => "SELECT c.*, s.revision FROM containers c
                JOIN projects p ON p.id = c.project_id $actor
                " . $revision('containers', 'c.id') . "
                WHERE c.deleted_at IS NULL AND $projectState AND $projectView ORDER BY c.id",
            'tasks' => "SELECT t.*, s.revision,
                " . AccessService::TASK_EDIT_SQL . " AS can_edit,
                " . AccessService::TASK_DELETE_SQL . " AS can_delete,
                " . AccessService::TASK_GROUP_MANAGE_SQL . " AS can_manage_groups,
                " . AccessService::TASK_LOCAL_GROUP_MANAGE_SQL . " AS can_manage_local_groups,
                " . AccessService::creatorAccessSql() . " AS creator_has_project_access
                FROM tasks t JOIN containers c ON c.id = t.container_id
                JOIN projects p ON p.id = c.project_id $actor
                LEFT JOIN users u ON u.id = t.created_by
                " . $revision('tasks', 't.id') . "
                WHERE $taskWhere ORDER BY t.id",
            'groups' => "SELECT g.*, s.revision FROM groups g $actor
                " . $revision('groups', 'g.id') . "
                WHERE $groupView OR EXISTS (
                    SELECT 1 FROM groups_tasks gt JOIN tasks t ON t.id = gt.task_id
                    JOIN containers c ON c.id = t.container_id JOIN projects p ON p.id = c.project_id
                    LEFT JOIN projects_groups pg ON pg.project_id = p.id AND pg.group_id = g.id
                    WHERE gt.group_id = g.id AND $taskWhere AND " . TaskAccess::ASSIGNED_GROUP_SQL . "
                ) ORDER BY g.id",
            'subtasks' => "SELECT q.*, s.revision FROM subtasks q $taskFrom
                " . $revision('subtasks', 'q.id') . "
                WHERE q.deleted_at IS NULL AND $taskWhere ORDER BY q.id",
            'task_attachments' => "SELECT q.*, s.revision FROM task_attachments q $taskFrom
                " . $revision('task_attachments', 'q.id') . "
                WHERE q.deleted_at IS NULL AND $taskWhere ORDER BY q.id",
            'users_groups' => "SELECT q.*, s.revision FROM users_groups q
                JOIN groups g ON g.id = q.groups_id JOIN users u ON u.id = q.user_id $actor
                " . $revision('users_groups', "CONCAT_WS(':', q.user_id, q.groups_id)") . "
                WHERE u.deleted_at IS NULL AND $groupView ORDER BY q.user_id, q.groups_id",
            'projects_groups' => "SELECT q.*, s.revision FROM projects_groups q
                JOIN projects p ON p.id = q.project_id JOIN groups g ON g.id = q.group_id $actor
                " . $revision('projects_groups', "CONCAT_WS(':', q.project_id, q.group_id)") . "
                WHERE $projectState AND $projectView AND g.personal_user_id IS NULL
                  AND (g.is_global = 1 OR g.project_id = p.id) ORDER BY q.project_id, q.group_id",
            'groups_tasks' => "SELECT q.*, s.revision FROM groups_tasks q $taskFrom
                JOIN groups g ON g.id = q.group_id
                LEFT JOIN projects_groups pg ON pg.project_id = p.id AND pg.group_id = g.id
                " . $revision('groups_tasks', "CONCAT_WS(':', q.group_id, q.task_id)") . "
                WHERE $taskWhere AND " . TaskAccess::ASSIGNED_GROUP_SQL . " ORDER BY q.group_id, q.task_id",
        ];
    }

    public function poll(int $userId, ?string $after = null): array {
        $previous = $after === null ? null : $this->decode($after, $userId);
        if ($this->db->inTransaction()) {
            throw new \LogicException('Change polling requires its own read snapshot');
        }
        // One consistent snapshot prevents mixing memberships and data from different commits.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->beginTransaction();
        try {
            $actor = $this->db->prepare('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL');
            $actor->execute(['id' => $userId]);
            if (!$actor->fetchColumn()) {
                throw new \DomainException('Benutzer nicht mehr aktiv');
            }
            $scopes = [];
            foreach ($this->queries() as $entity => $sql) {
                $scopes[$entity] = $this->fingerprint($sql, $userId);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
        $changes = [];
        if ($previous !== null) {
            foreach ($scopes as $entity => $hash) {
                if (!hash_equals($previous[$entity], $hash)) {
                    $changes[] = ['entity' => $entity, 'action' => 'invalidate'];
                }
            }
        }
        return [
            'next_cursor' => $this->encode(['v' => 1, 'user' => $userId, 'scopes' => $scopes]),
            'refresh_required' => $changes !== [],
            'changes' => $changes,
        ];
    }
}
