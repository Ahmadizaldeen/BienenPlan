<?php

namespace BienenPlan\Models;

use BienenPlan\Services\AccessService;

final class TaskAccess {
    public const VISIBILITY_SQL = AccessService::TASK_VIEW_SQL;

    // Assignment metadata is not a permission check; personal groups grant no project access.
    public const ASSIGNED_GROUP_SQL = '(
        (pg.group_id IS NOT NULL AND g.personal_user_id IS NULL
            AND (g.is_global = 1 OR g.project_id = c.project_id))
        OR g.personal_user_id = t.created_by
    )';
}
