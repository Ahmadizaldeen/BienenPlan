<?php

require __DIR__ . '/../vendor/autoload.php';

use BienenPlan\Config\Database;
use BienenPlan\Controllers\ProjectController;
use BienenPlan\Controllers\GroupController;
use BienenPlan\Controllers\SubtaskController;
use BienenPlan\Controllers\TaskAttachmentController;
use BienenPlan\Controllers\TaskController;
use BienenPlan\Models\Group;
use BienenPlan\Models\Project;
use BienenPlan\Models\Task;
use BienenPlan\Models\TaskAttachment;
use BienenPlan\Models\Subtask;
use BienenPlan\Models\User;
use BienenPlan\Services\AccessService;
use BienenPlan\Services\Env;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

Env::load();
$db = Database::getConnection();
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$tables = [
    'users' => 'id INT PRIMARY KEY, name VARCHAR(100), email VARCHAR(100), created_at DATETIME,
        is_admin BOOLEAN DEFAULT FALSE, deleted_at DATETIME NULL',
    'projects' => 'id INT PRIMARY KEY, name VARCHAR(100), created_by INT, archived_at DATETIME NULL',
    'groups' => 'id INT PRIMARY KEY, name VARCHAR(100), project_id INT NULL,
        is_global BOOLEAN DEFAULT FALSE, personal_user_id INT NULL, created_at DATETIME,
        UNIQUE (project_id, name)',
    'projects_groups' => 'project_id INT, group_id INT, PRIMARY KEY (project_id, group_id)',
    'users_groups' => "user_id INT, groups_id INT, role VARCHAR(20) DEFAULT 'member', PRIMARY KEY (user_id, groups_id)",
    'containers' => 'id INT PRIMARY KEY, title VARCHAR(100), project_id INT, created_by INT, deleted_at DATETIME NULL',
    'tasks' => "id INT PRIMARY KEY, container_id INT, created_by INT, title VARCHAR(100), description TEXT,
        status VARCHAR(20), deadline DATETIME NULL, created_at DATETIME, deleted_at DATETIME NULL, deleted_by INT NULL",
    'groups_tasks' => 'task_id INT, group_id INT, PRIMARY KEY (task_id, group_id)',
    'subtasks' => 'id INT AUTO_INCREMENT PRIMARY KEY, task_id INT, created_by INT, title VARCHAR(100),
        completed BOOLEAN DEFAULT FALSE, deleted_at DATETIME NULL, deleted_by INT NULL',
];
$created = [];
try {
    foreach ($tables as $name => $definition) {
        $db->exec("CREATE TEMPORARY TABLE `$name` ($definition) ENGINE=InnoDB");
        $created[] = $name;
    }
    $db->exec("INSERT INTO users (id, name, is_admin, deleted_at) VALUES
        (1, 'Owner', 0, NULL), (2, 'Assignee', 0, NULL), (3, 'Second', 0, NULL),
        (4, 'Other owner', 0, NULL), (5, 'Admin', 1, NULL), (6, 'Inactive', 0, NOW());
        INSERT INTO projects (id, name, created_by) VALUES (1, 'Project', 1), (2, 'Other', 4);
        INSERT INTO groups (id, name, project_id, is_global, personal_user_id) VALUES
        (10, 'Solo', 1, 0, NULL), (11, 'Team', 1, 0, NULL),
        (12, 'Personal user 2', NULL, 0, 2), (13, 'Foreign', 2, 0, NULL),
        (14, 'Global', NULL, 1, NULL), (15, 'Unlinked', 1, 0, NULL), (16, 'Empty', 1, 0, NULL);
        INSERT INTO users_groups (user_id, groups_id, role) VALUES
        (2, 10, 'owner'), (2, 11, 'member'), (3, 11, 'member'), (2, 12, 'owner'),
        (2, 13, 'member'), (2, 14, 'member'), (2, 15, 'member');
        INSERT INTO projects_groups VALUES (1, 10), (1, 11), (1, 14), (2, 13), (1, 16);
        INSERT INTO containers (id, title, project_id, created_by) VALUES (1, 'Container', 1, 1);
        INSERT INTO tasks (id, container_id, created_by, title, description, status, deadline) VALUES
        (1, 1, 1, 'Solo task', 'Keep description', 'in_progress', '2026-10-12 12:00:00'),
        (2, 1, 1, 'Team task', 'Keep description', 'open', NULL),
        (3, 1, 1, 'Private', 'Keep description', 'open', NULL),
        (4, 1, 1, 'Global task', 'Keep description', 'open', NULL);
        INSERT INTO groups_tasks VALUES (1, 10), (2, 11), (3, 12), (3, 15), (3, 13), (4, 14);");

    $access = new AccessService($db);
    $tasks = new Task($db);
    $projects = new Project($db);
    $groups = new Group($db);
    $taskController = new TaskController($tasks, new TaskAttachmentController(new TaskAttachment($db)));
    $projectController = new ProjectController($projects);
    $request = (new ServerRequestFactory())->createServerRequest('PUT', '/')->withAttribute('user_id', 2);
    $owner = $request->withAttribute('user_id', 1);
    $admin = $request->withAttribute('user_id', 5);
    $viewerGroups = array_column($projects->getGroups(1, 2), null, 'id');
    check($viewerGroups[10]['is_current_user_member'] == 1 && $viewerGroups[16]['is_current_user_member'] == 0, 'Group membership metadata belongs to current viewer');
    check($tasks->getById(1, 2)['can_create_subtasks'] == 1
        && $tasks->getById(2, 2)['can_create_subtasks'] == 1, 'Assigned local-group members may create subtasks across group sizes');
    $groupController = new GroupController($groups, new User($db));
    $subtasks = new Subtask($db);
    $subtaskController = new SubtaskController($subtasks);

    check($tasks->getById(1, 2)['can_manage_local_groups'] == 1
        && $tasks->getById(1, 2)['can_manage_groups'] == 0, 'Assigned group member receives local-only assignment rights');
    check($access->canManageTaskGroup(2, 1, 11), 'Solo member may manage local target group');
    check(!$access->canManageTaskGroup(2, 1, 14)
        && !$access->canManageTaskGroup(2, 1, 13)
        && !$access->canManageTaskGroup(2, 1, 12), 'Global, foreign and personal targets are forbidden');
    check($groupController->assignGroup($request, new Response(), ['taskId' => '1', 'groupId' => '11'])->getStatusCode() === 201, 'Solo member adds local team assignment');
    check($groupController->removeGroup($request, new Response(), ['taskId' => '1', 'groupId' => '11'])->getStatusCode() === 200, 'Solo member removes local team assignment');
    check(!$groups->assignGroup(1, 14, 2) && !$groups->assignGroup(1, 13, 2)
        && !$groups->assignGroup(1, 12, 2) && !$groups->assignGroup(1, 15, 2), 'Model rejects global, foreign, personal and unlinked assignments');
    check($groupController->assignGroup($request, new Response(), ['taskId' => '1', 'groupId' => '14'])->getStatusCode() === 403, 'Controller rejects global assignment');
    check($groups->assignGroup(1, 14, 1), 'Owner may still assign global groups');
    check(!$groups->removeGroup(1, 14, 2), 'Solo member cannot remove assigned global group');
    check($groups->removeGroup(1, 14, 1), 'Owner may still remove global assignment');
    check($groups->assignGroup(2, 16, 2), 'Member of an assigned local group may add local assignments');

    $db->exec("INSERT INTO subtasks (task_id, created_by, title) VALUES (1, 1, 'Owner item')");
    $subtaskList = json_decode((string) $subtaskController->index($request, new Response(), ['taskId' => '1'])->getBody(), true);
    check($subtaskList['can_create'] && $subtaskList['subtasks'][0]['can_complete'], 'Solo member can create and complete subtasks');
    check($subtaskList['subtasks'][0]['can_edit'] && $subtaskList['subtasks'][0]['can_delete'], 'Assigned local-group member can fully manage another creator subtask');
    check($subtaskController->create($request->withParsedBody(['title' => 'Solo item']), new Response(), ['taskId' => '1'])->getStatusCode() === 201, 'Solo member creates subtask through API');
    check($subtaskController->update($request->withParsedBody(['completed' => true]), new Response(), ['taskId' => '1', 'subtaskId' => '1'])->getStatusCode() === 200, 'Solo member checks owner subtask');
    check($subtaskController->update($request->withParsedBody(['completed' => false]), new Response(), ['taskId' => '1', 'subtaskId' => '1'])->getStatusCode() === 200, 'Solo member unchecks subtask');
    check($subtaskController->create($request->withParsedBody(['title' => 'Team item']), new Response(), ['taskId' => '2'])->getStatusCode() === 201, 'Member of a multi-member assigned local group can create subtasks');

    check($access->canEditTaskTitleDeadline(2, 1) && $access->canEditTask(2, 1), 'Solo assignment grants content and deadline rights');
    check($access->canEditTaskTitleDeadline(2, 2) && $access->canEditTask(3, 2), 'Every member of an assigned local group can edit task content');
    check($access->canEditTaskTitleDeadline(2, 3), 'Owner of an assigned personal group can edit task content');
    check($access->canViewTask(2, 4) && !$access->canEditTaskTitleDeadline(2, 4),
        'A global-group assignment grants read access but not task-content rights');
    check(!$access->canDeleteTask(2, 1) && !$access->canManageTaskGroups(2, 1), 'Solo assignment grants no deletion or group-management rights');
    foreach ($tasks->getAllByUser(2) as $item) {
        check($item['can_edit'] == $tasks->getById((int) $item['id'], 2)['can_edit'], 'List and detail content rights agree');
        check($item['can_edit_title_deadline'] == $tasks->getById((int) $item['id'], 2)['can_edit_title_deadline'], 'List and detail rights agree');
    }
    check($access->accessibleTask(2, 1)['can_edit_title_deadline'] == 1, 'Nested task metadata exposes the same right');
    $counts = array_column($projects->getGroups(1), 'member_count', 'id');
    check($counts[10] == 1 && $counts[11] == 2 && $counts[16] == 0, 'Group lists report exact active member counts');

    $response = $taskController->update($request->withParsedBody(['title' => 'Changed', 'deadline' => null]), new Response(), ['id' => '1']);
    check($response->getStatusCode() === 200, 'Solo member can change title and remove deadline');
    $task = $tasks->getById(1, 2);
    check($task['title'] === 'Changed' && $task['deadline'] === null
        && $task['description'] === 'Keep description' && $task['status'] === 'in_progress', 'Partial update preserves description and status');
    check($tasks->update(1, ['title' => 'Changed', 'deadline' => null], 2), 'Unchanged authorized update succeeds');
    check($tasks->update(1, ['title' => 'Changed again', 'deadline' => '2026-10-13 15:00:00'], 2), 'Solo member can set a deadline');
    check($tasks->update(1, ['title' => 'Title only'], 2)
        && $tasks->getById(1, 2)['deadline'] === '2026-10-13 15:00:00', 'Omitted deadline remains unchanged');
    $content = ['title' => 'Title only', 'description' => 'Updated description', 'status' => 'in_progress', 'deadline' => '2026-10-13 15:00:00'];
    check($taskController->update($request->withParsedBody($content), new Response(), ['id' => '1'])->getStatusCode() === 200, 'Solo member can update description through the normal task endpoint');
    check($tasks->getById(1, 2)['description'] === 'Updated description', 'Description update is persisted');
    check($tasks->update(1, $content, 2), 'Model accepts authorized content updates');
    check($taskController->update($request->withParsedBody($content), new Response(), ['id' => '2'])->getStatusCode() === 200, 'Multi-member local-group assignee can update description and status');
    check($taskController->update($request->withParsedBody(['title' => 'Team title']), new Response(), ['id' => '2'])->getStatusCode() === 200, 'Multi-member local-group assignee can change title');

    $args = ['id' => '1', 'groupId' => '10'];
    check($projectController->updateGroup($request->withParsedBody(['name' => 'No', 'user_ids' => [2]]), new Response(), $args)->getStatusCode() === 403, 'Member cannot edit group');
    check($projectController->updateGroup($owner->withParsedBody(['name' => 'Renamed', 'user_ids' => [2, 3, 3]]), new Response(), $args)->getStatusCode() === 200, 'Owner edits name and deduplicated members atomically');
    check($access->canEditTaskTitleDeadline(2, 1) && $access->canEditTaskTitleDeadline(3, 1), 'Every active member of an assigned local group retains task rights');
    check($groups->assignGroup(1, 16, 2), 'Multi-member assigned local group can assign another local group');
    check($subtaskController->create($request->withParsedBody(['title' => 'Team item']), new Response(), ['taskId' => '1'])->getStatusCode() === 201, 'Multi-member assigned local group can create subtasks');
    $teamSubtasks = json_decode((string) $subtaskController->index($request, new Response(), ['taskId' => '1'])->getBody(), true);
    check($teamSubtasks['can_create'] && $teamSubtasks['subtasks'][0]['can_edit']
        && $teamSubtasks['subtasks'][0]['can_complete'] && $teamSubtasks['subtasks'][0]['can_delete'], 'Assigned local group has full subtask CRUD');
    check($subtaskController->update($request->withParsedBody(['title' => 'Group member rename', 'completed' => true]), new Response(), ['taskId' => '1', 'subtaskId' => '1'])->getStatusCode() === 200, 'Assigned local-group member can edit and complete subtasks');
    check($subtaskController->delete($request, new Response(), ['taskId' => '1', 'subtaskId' => '2'])->getStatusCode() === 200, 'Assigned local-group member can delete another creator subtask');
    check($subtasks->delete(1, 1, 2), 'Subtask model allows assigned local-group member deletion');
    try {
        $subtasks->create(1, 4, 'Denied directly');
        throw new RuntimeException('Unauthorized model creation must fail');
    } catch (DomainException $exception) {
        check(true, 'Subtask model also checks current task assignment');
    }
    check($tasks->update(1, ['title' => 'Allowed', 'description' => 'Group update'], 2)
        && $tasks->getById(1, 2)['description'] === 'Group update', 'Multi-member assigned group retains task-content writes');
    check($db->query('SELECT role FROM users_groups WHERE groups_id = 10 AND user_id = 2')->fetchColumn() === 'owner', 'Retained member role is preserved');
    check($projectController->updateGroup($admin->withParsedBody(['name' => 'Solo again', 'user_ids' => [2]]), new Response(), $args)->getStatusCode() === 200, 'Admin edits a foreign-owned local group');
    check($access->canEditTaskTitleDeadline(2, 1) && !$access->canViewTask(3, 1), 'Removing a user from the assigned group revokes only that user access');
    check($groups->addUserToGroup(3, 10, 1) && $access->canEditTaskTitleDeadline(3, 1), 'Group membership grants the same task rights to each member');
    check($groups->removeUserFromGroup(3, 10, 1) && $access->canEditTaskTitleDeadline(2, 1), 'Removing another group member does not revoke retained member rights');
    $db->exec('INSERT INTO users_groups (user_id, groups_id) VALUES (6, 10)');
    check($access->canEditTaskTitleDeadline(2, 1), 'Additional group members do not change retained member rights');
    $db->exec('UPDATE users SET deleted_at = NULL WHERE id = 6');
    check($access->canEditTaskTitleDeadline(2, 1), 'Reactivating another group member does not revoke task rights');
    $db->exec('UPDATE users SET deleted_at = NOW() WHERE id = 6');
    check($projectController->updateGroup($owner->withParsedBody(['name' => 'Invalid', 'user_ids' => [2, 999]]), new Response(), $args)->getStatusCode() === 400, 'Unknown member rejects entire edit');
    check($groups->findGroupById(10)['name'] === 'Solo again' && !$db->inTransaction(), 'Invalid edit rolls back name and memberships');
    check($projectController->updateGroup($owner->withParsedBody(['name' => 'Invalid', 'user_ids' => [2, 6]]), new Response(), $args)->getStatusCode() === 400, 'Inactive member rejects entire edit');
    foreach ([
        ['name' => 'No members', 'user_ids' => []],
        ['name' => '', 'user_ids' => [2]],
        ['name' => 'Personal user 2', 'user_ids' => [2]],
        ['name' => 'Invalid', 'user_ids' => ['bad']],
    ] as $data) {
        check($projectController->updateGroup($owner->withParsedBody($data), new Response(), $args)->getStatusCode() === 400, 'Invalid group data is rejected');
    }
    foreach ([12, 13, 14, 999] as $groupId) {
        check(!$projects->updateGroup(1, $groupId, 'No', [2], 5), 'Only this project local groups are editable');
    }
    check(!$projects->updateGroup(1, 10, 'Denied', [2], 2), 'Model enforces owner/admin authorization');
    check($projectController->updateGroup($owner->withParsedBody(['name' => 'Team', 'user_ids' => [2]]), new Response(), $args)->getStatusCode() === 409, 'Duplicate local name is reported');
    check($groups->findGroupById(10)['name'] === 'Solo again', 'Conflicting edit leaves the group intact');

    $racingTask = new class($db) extends Task {
        public function __construct(private PDO $database) { parent::__construct($database); }
        public function canEdit(int $taskId, int $userId): bool {
            $allowed = parent::canEdit($taskId, $userId);
            if ($allowed) $this->database->exec('DELETE FROM groups_tasks WHERE task_id = 1 AND group_id = 10');
            return $allowed;
        }
    };
    $racingController = new TaskController($racingTask, new TaskAttachmentController(new TaskAttachment($db)));
    check($racingController->update($request->withParsedBody(['title' => 'Race', 'description' => 'Race']), new Response(), ['id' => '1'])->getStatusCode() === 409, 'Content write rechecks task assignment after earlier permission check');
    check($tasks->getById(1)['title'] === 'Allowed'
        && $tasks->getById(1)['description'] === 'Group update', 'Revoked write changes nothing');
    $db->exec('DELETE FROM users_groups WHERE groups_id = 10 AND user_id = 3');
    check($groups->assignGroup(1, 10, 1), 'Project owner restores the task assignment');
    check($groups->removeGroup(1, 10, 2), 'Solo member can remove their own local assignment');
    check(!$groups->assignGroup(1, 10, 2), 'Self-removal cannot be reversed without remaining solo assignment');
    $db->exec('INSERT INTO groups_tasks VALUES (1, 10)');
    $db->exec('DELETE FROM projects_groups WHERE project_id = 1 AND group_id = 10');
    check(!$access->canEditTaskTitleDeadline(2, 1), 'Unlinking the group revokes extra rights even with another project membership');
    $db->exec('INSERT INTO projects_groups VALUES (1, 10)');
    $db->exec('UPDATE projects SET archived_at = NOW() WHERE id = 1');
    check(!$access->canEditTaskTitleDeadline(2, 1) && !$access->canEditTaskTitleDeadline(5, 1), 'Archive blocks limited editing for everyone');
    check($tasks->getById(1, 5)['can_edit_title_deadline'] == 0, 'Archived detail reports limited editing disabled');
    check(!$projects->updateGroup(1, 10, 'Archived', [2], 5), 'Archive also blocks local group editing');
    check(!$groups->assignGroup(1, 16, 2) && !$groups->removeGroup(1, 10, 2), 'Archive blocks local assignments');
    check($subtaskController->create($admin->withParsedBody(['title' => 'Denied']), new Response(), ['taskId' => '1'])->getStatusCode() === 403, 'Archive blocks subtask creation');

    echo "Single-member group tests passed\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    foreach (array_reverse($created) as $name) $db->exec("DROP TEMPORARY TABLE IF EXISTS `$name`");
}
