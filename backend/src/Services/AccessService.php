<?php

namespace BienenPlan\Services;

use PDO;

final class AccessService {
    // SQL aliases: a = active actor, p = project, c = container, t = task, g = group.

    // Checks if the active actor is an admin or the creator of the project.
    public const ACTOR_JOIN = 
    'JOIN users a ON a.id = :access_user AND a.deleted_at IS NULL';

    // Checks if the active actor is a member of the project through any group.
    public const MEMBER_SQL = 
    'EXISTS (
        SELECT 1 FROM projects_groups membership_pg
        JOIN groups membership_g ON membership_g.id = membership_pg.group_id
        JOIN users_groups membership_ug ON membership_ug.groups_id = membership_g.id
        WHERE membership_pg.project_id = p.id AND membership_ug.user_id = a.id
          AND membership_g.personal_user_id IS NULL
          AND (membership_g.project_id = p.id OR membership_g.is_global = 1)
    )'; 

     // Checks if the active actor can manage the project (admin or creator).
    public const PROJECT_MANAGE_SQL =
     '(a.is_admin = 1 OR p.created_by = a.id)';

    // Checks if the active actor can read the project (not archived or admin).
    public const PROJECT_READ_STATE_SQL =
     '(p.archived_at IS NULL OR a.is_admin = 1)';

    // Checks if the active actor can view the project (manage or member).
    public const PROJECT_VIEW_SQL = 
     '(' . self::PROJECT_MANAGE_SQL . ' OR ' . self::MEMBER_SQL . ')';

    // Task creators retain access through project membership; assigned global groups
    // grant read access, while local/personal assignments grant collaborator access.
    public const TASK_VIEW_SQL = '(' . self::PROJECT_MANAGE_SQL . ' OR (
        (' . self::MEMBER_SQL . ' AND t.created_by = a.id)
        OR (' . self::MEMBER_SQL . ' AND ' . self::TASK_GLOBAL_ASSIGNED_VIEW_SQL . ')
        OR ' . self::TASK_ASSIGNED_COLLABORATOR_SQL . '
    ))';

    public const GROUP_MEMBER_COUNT_SQL = '(SELECT COUNT(DISTINCT counted_ug.user_id)
        FROM users_groups counted_ug JOIN users counted_u ON counted_u.id = counted_ug.user_id
        WHERE counted_ug.groups_id = g.id AND counted_u.deleted_at IS NULL)';

    public const TASK_GLOBAL_ASSIGNED_VIEW_SQL = 'EXISTS (
        SELECT 1 FROM groups_tasks global_gt
        JOIN projects_groups global_pg
          ON global_pg.project_id = p.id AND global_pg.group_id = global_gt.group_id
        JOIN groups global_g ON global_g.id = global_gt.group_id
        JOIN users_groups global_ug ON global_ug.groups_id = global_g.id
        WHERE global_gt.task_id = t.id AND global_ug.user_id = a.id
          AND global_g.is_global = 1 AND global_g.personal_user_id IS NULL
    )';

    // Task assignment grants task-scoped access to local group members and the
    // owner of an assigned personal group. Global groups do not grant edit rights.
    public const TASK_ASSIGNED_COLLABORATOR_SQL = 'EXISTS (
        SELECT 1 FROM groups_tasks collaborator_gt
        JOIN groups collaborator_g ON collaborator_g.id = collaborator_gt.group_id
        WHERE collaborator_gt.task_id = t.id AND (
            (collaborator_g.personal_user_id = a.id)
            OR (collaborator_g.personal_user_id IS NULL AND collaborator_g.is_global = 0
                AND collaborator_g.project_id = p.id
                AND EXISTS (
                    SELECT 1 FROM projects_groups collaborator_pg
                    WHERE collaborator_pg.project_id = p.id
                      AND collaborator_pg.group_id = collaborator_g.id
                )
                AND EXISTS (
                    SELECT 1 FROM users_groups collaborator_ug
                    WHERE collaborator_ug.groups_id = collaborator_g.id
                      AND collaborator_ug.user_id = a.id
                )
            )
        )
    )';

    public const TASK_EDIT_SQL = '(p.archived_at IS NULL AND (
        ' . self::PROJECT_MANAGE_SQL . '
        OR (' . self::MEMBER_SQL . ' AND t.created_by = a.id)
        OR ' . self::TASK_ASSIGNED_COLLABORATOR_SQL . '
    ))';

    public const TASK_TITLE_DEADLINE_EDIT_SQL = self::TASK_EDIT_SQL;

    // Checks if the active actor can delete the task (project not archived and either manage project or member with task ownership or container ownership).
    // Container owners may delete private tasks without being allowed to read them.
    public const TASK_DELETE_SQL = '(p.archived_at IS NULL AND (' . self::PROJECT_MANAGE_SQL . ' OR (
        ' . self::MEMBER_SQL . ' AND (t.created_by = a.id OR c.created_by = a.id))))';

    // Checks if the active actor can manage the task group (project not archived and manage project).
    public const TASK_GROUP_MANAGE_SQL = '(p.archived_at IS NULL AND ' . self::PROJECT_MANAGE_SQL . ')';
    public const TASK_LOCAL_GROUP_MANAGE_SQL = '(p.archived_at IS NULL AND (
        ' . self::PROJECT_MANAGE_SQL . ' OR ' . self::TASK_ASSIGNED_COLLABORATOR_SQL . '))';
    public const TASK_TARGET_GROUP_MANAGE_SQL = '(' . self::PROJECT_MANAGE_SQL . '
        OR (g.personal_user_id IS NULL AND g.is_global = 0 AND g.project_id = p.id
            AND ' . self::TASK_ASSIGNED_COLLABORATOR_SQL . '))';

    public const CONTAINER_MANAGE_SQL = '(' . self::PROJECT_MANAGE_SQL .
        ' OR (' . self::MEMBER_SQL . ' AND c.created_by = a.id))';

    public const GROUP_MANAGE_SQL = '(g.personal_user_id IS NULL
        AND (g.project_id IS NULL OR EXISTS (
            SELECT 1 FROM projects active_p WHERE active_p.id = g.project_id AND active_p.archived_at IS NULL))
        AND (a.is_admin = 1 OR EXISTS (
            SELECT 1 FROM projects p WHERE p.id = g.project_id
              AND p.archived_at IS NULL AND p.created_by = a.id)))';

    // Checks if the active actor can view the group (admin, global, personal, or project member).
    public const GROUP_VIEW_SQL = '(a.is_admin = 1 OR g.is_global = 1 OR g.personal_user_id = a.id
        OR EXISTS (SELECT 1 FROM projects p
            WHERE p.id = g.project_id AND p.archived_at IS NULL AND ' . self::PROJECT_VIEW_SQL . '))';

    public function __construct(private PDO $pdo) {}

    public static function taskWriteQuery(string $itemRule = '1 = 1'): string {
        return 'SELECT t.id FROM tasks t JOIN containers c ON c.id = t.container_id
            JOIN projects p ON p.id = c.project_id ' . self::ACTOR_JOIN . '
            WHERE t.id = :write_task AND t.deleted_at IS NULL AND c.deleted_at IS NULL
              AND p.archived_at IS NULL AND ' . self::TASK_VIEW_SQL . ' AND (' . $itemRule . ')';
    }

    // Checks if the active actor has creator-level access (admin, creator of the project, or member).
    public static function creatorAccessSql(): string {
        return '(u.id IS NOT NULL AND u.deleted_at IS NULL AND (u.is_admin = 1 OR u.id = p.created_by OR ' .
            str_replace('a.id', 'u.id', self::MEMBER_SQL) . '))';
    }

    private function exists(string $sql, array $parameters): bool {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);
        return (bool) $stmt->fetchColumn();
    }

    public function isAdmin(int $userId): bool {
        return $this->exists('SELECT 1 FROM users WHERE id = :id AND deleted_at IS NULL AND is_admin = 1', ['id' => $userId]);
    }

    private function project(int $userId, int $projectId, string $rule, bool $read = false): bool {
        return $this->exists('SELECT 1 FROM projects p ' . self::ACTOR_JOIN .
            ' WHERE p.id = :id AND ' . ($read ? self::PROJECT_READ_STATE_SQL : 'p.archived_at IS NULL') . ' AND ' . $rule,
            ['access_user' => $userId, 'id' => $projectId]);
    }

    public function canViewProject(int $userId, int $projectId): bool {
        return $this->project($userId, $projectId, self::PROJECT_VIEW_SQL, true);
    }

    public function canManageProject(int $userId, int $projectId): bool {
        return $this->project($userId, $projectId, self::PROJECT_MANAGE_SQL);
    }

    public function canCreateInProject(int $userId, int $projectId): bool {
        return $this->project($userId, $projectId, self::PROJECT_VIEW_SQL);
    }

    private function container(int $userId, int $containerId, string $rule, bool $read = false): bool {
        return $this->exists('SELECT 1 FROM containers c JOIN projects p ON p.id = c.project_id ' .
            self::ACTOR_JOIN . ' WHERE c.id = :id AND c.deleted_at IS NULL AND ' .
            ($read ? self::PROJECT_READ_STATE_SQL : 'p.archived_at IS NULL') . ' AND ' . $rule,
            ['access_user' => $userId, 'id' => $containerId]);
    }

    public function canViewContainer(int $userId, int $containerId): bool {
        return $this->container($userId, $containerId, self::PROJECT_VIEW_SQL, true);
    }

    public function canCreateTask(int $userId, int $containerId): bool {
        return $this->container($userId, $containerId, self::PROJECT_VIEW_SQL);
    }

    public function canManageContainer(int $userId, int $containerId): bool {
        return $this->container($userId, $containerId, self::CONTAINER_MANAGE_SQL);
    }

    public function accessibleTask(int $userId, int $taskId): ?array {
        $stmt = $this->pdo->prepare('SELECT t.id, t.created_by AS task_owner,
            c.created_by AS container_owner, p.created_by AS project_owner, p.id AS project_id,
            a.is_admin, p.archived_at, (p.archived_at IS NULL) AS can_write,
            ' . self::TASK_EDIT_SQL . ' AS can_edit,
            ' . self::TASK_TITLE_DEADLINE_EDIT_SQL . ' AS can_edit_title_deadline,
            ' . self::TASK_DELETE_SQL . ' AS can_delete,
            ' . self::TASK_GROUP_MANAGE_SQL . ' AS can_manage_groups
            , ' . self::TASK_LOCAL_GROUP_MANAGE_SQL . ' AS can_manage_local_groups
            FROM tasks t JOIN containers c ON c.id = t.container_id
            JOIN projects p ON p.id = c.project_id ' . self::ACTOR_JOIN . '
            WHERE t.id = :id AND t.deleted_at IS NULL AND c.deleted_at IS NULL
              AND ' . self::PROJECT_READ_STATE_SQL . ' AND ' . self::TASK_VIEW_SQL);
        $stmt->execute(['access_user' => $userId, 'id' => $taskId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function task(int $userId, int $taskId, string $rule, bool $read = false): bool {
        return $this->exists('SELECT 1 FROM tasks t JOIN containers c ON c.id = t.container_id
            JOIN projects p ON p.id = c.project_id ' . self::ACTOR_JOIN . '
            WHERE t.id = :id AND t.deleted_at IS NULL AND c.deleted_at IS NULL
              AND ' . ($read ? self::PROJECT_READ_STATE_SQL : 'p.archived_at IS NULL') . ' AND ' . $rule,
            ['access_user' => $userId, 'id' => $taskId]);
    }

    public function canViewTask(int $userId, int $taskId): bool {
        return $this->task($userId, $taskId, self::TASK_VIEW_SQL, true);
    }

    public function canChangeTaskStatus(int $userId, int $taskId): bool {
        return $this->task($userId, $taskId, self::TASK_VIEW_SQL);
    }

    public function canEditTask(int $userId, int $taskId): bool {
        return $this->task($userId, $taskId, self::TASK_EDIT_SQL);
    }

    public function canEditTaskTitleDeadline(int $userId, int $taskId): bool {
        return $this->task($userId, $taskId, self::TASK_TITLE_DEADLINE_EDIT_SQL);
    }

    public function canDeleteTask(int $userId, int $taskId): bool {
        return $this->task($userId, $taskId, self::TASK_DELETE_SQL);
    }

    public function canManageTaskGroups(int $userId, int $taskId): bool {
        return $this->task($userId, $taskId, self::PROJECT_MANAGE_SQL);
    }

    public function canManageTaskGroup(int $userId, int $taskId, int $groupId): bool {
        return $this->exists('SELECT 1 FROM tasks t JOIN containers c ON c.id = t.container_id
            JOIN projects p ON p.id = c.project_id JOIN groups g ON g.id = :group_id
            ' . self::ACTOR_JOIN . ' WHERE t.id = :task_id AND t.deleted_at IS NULL
              AND c.deleted_at IS NULL AND p.archived_at IS NULL
              AND ' . self::TASK_VIEW_SQL . ' AND ' . self::TASK_TARGET_GROUP_MANAGE_SQL,
            ['group_id' => $groupId, 'task_id' => $taskId, 'access_user' => $userId]);
    }

    public function canViewGroup(int $userId, int $groupId): bool {
        return $this->exists('SELECT 1 FROM groups g ' . self::ACTOR_JOIN .
            ' WHERE g.id = :id AND ' . self::GROUP_VIEW_SQL,
            ['access_user' => $userId, 'id' => $groupId]);
    }

    public function canManageGroup(int $userId, int $groupId): bool {
        return $this->exists('SELECT 1 FROM groups g ' . self::ACTOR_JOIN . '
            WHERE g.id = :id AND ' . self::GROUP_MANAGE_SQL,
            ['access_user' => $userId, 'id' => $groupId]);
    }

    // These item rules require an accessibleTask() result, not an unchecked task row.
    public function canCreateSubtask(array $task, int $userId): bool {
        return (bool) $task['can_write'] && (bool) $task['can_edit'];
    }

    private function canManageTaskContents(array $task, int $userId): bool {
        return (bool) $task['is_admin'] || (int) $task['project_owner'] === $userId;
    }

    public function canEditSubtask(array $task, array $item, int $userId): bool {
        return (bool) $task['can_write'] && (bool) $task['can_edit'];
    }

    public function canDeleteSubtask(array $task, array $item, int $userId): bool {
        return (bool) $task['can_write'] && (bool) $task['can_edit'];
    }

    public function canDeleteAttachment(array $task, array $item, int $userId): bool {
        return (bool) $task['can_write'] && ($this->canManageTaskContents($task, $userId) || (int) ($item['uploaded_by'] ?? 0) === $userId);
    }
}
