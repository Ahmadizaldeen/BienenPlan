<?php

require __DIR__ . '/../vendor/autoload.php';

use BienenPlan\Config\Database;
use BienenPlan\Controllers\ContainerController;
use BienenPlan\Controllers\TaskAttachmentController;
use BienenPlan\Controllers\TaskController;
use BienenPlan\Models\Container;
use BienenPlan\Models\Group;
use BienenPlan\Models\Subtask;
use BienenPlan\Models\Task;
use BienenPlan\Models\TaskAttachment;
use BienenPlan\Services\Env;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

Env::load();
$db = Database::getConnection();
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// Connection-local temporary tables shadow production tables without changing their data.
$tables = [
    'users' => 'id INT PRIMARY KEY, name VARCHAR(100), deleted_at DATETIME NULL, is_admin BOOLEAN NOT NULL DEFAULT FALSE',
    'groups' => 'id INT PRIMARY KEY, name VARCHAR(100), personal_user_id INT NULL UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, project_id INT NULL, is_global BOOLEAN NOT NULL DEFAULT FALSE',
    'users_groups' => 'user_id INT, groups_id INT, PRIMARY KEY (user_id, groups_id)',
    'projects' => 'id INT PRIMARY KEY, name VARCHAR(100), created_by INT, archived_at DATETIME NULL',
    'projects_groups' => 'project_id INT, group_id INT, PRIMARY KEY (project_id, group_id)',
    'containers' => 'id INT PRIMARY KEY, project_id INT, title VARCHAR(100), created_by INT, deleted_at DATETIME NULL',
    'tasks' => "id INT AUTO_INCREMENT PRIMARY KEY, container_id INT, created_by INT, title VARCHAR(100),
        description TEXT NULL, status VARCHAR(20) DEFAULT 'open', deadline DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, deleted_by INT NULL",
    'groups_tasks' => 'task_id INT, group_id INT, PRIMARY KEY (task_id, group_id)',
];
$createdTables = [];

try {
    foreach ($tables as $name => $columns) {
        $db->exec("CREATE TEMPORARY TABLE `$name` ($columns) ENGINE=InnoDB");
        $createdTables[] = $name;
    }
    $db->exec("INSERT INTO users (id, name) VALUES (1, 'Owner'), (2, 'Creator'), (3, 'Other member'), (4, 'Stranger');
        INSERT INTO groups (id, name, personal_user_id) VALUES
            (10, 'Team', NULL), (20, 'Personal user 1', 1), (21, 'Personal user 2', 2), (22, 'Personal user 4', 4);
        INSERT INTO users_groups VALUES (2, 10), (3, 10), (1, 20), (2, 21), (4, 22);
        INSERT INTO projects VALUES (1, 'Shared', 1, NULL), (2, 'Own', 2, NULL);
        INSERT INTO projects_groups VALUES (1, 10);
        INSERT INTO containers VALUES (1, 1, 'Shared container', 1, NULL), (2, 2, 'Own container', 2, NULL);");
    $db->exec('UPDATE groups SET project_id = 1 WHERE id = 10');

    $tasks = new Task($db);
    $attachments = new TaskAttachment($db);
    $subtasks = new Subtask($db);
    $groups = new Group($db);
    $controller = new TaskController($tasks, new TaskAttachmentController($attachments));
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/tasks')->withAttribute('user_id', 2);
    $payload = ['title' => 'Without selected group', 'container_id' => 1, 'created_by' => 4];
    $response = $controller->create($request->withParsedBody($payload), new Response());
    check($response->getStatusCode() === 201, 'Member can create without a selected group in someone else\'s project');
    $id = json_decode((string) $response->getBody(), true)['id'];
    check((int) $tasks->getById($id)['created_by'] === 2, 'Authenticated user is the creator, not a submitted user ID');
    check((int) $db->query("SELECT group_id FROM groups_tasks WHERE task_id = $id")->fetchColumn() === 21, 'Creator is assigned through their personal group');
    check((int) $db->query('SELECT COUNT(*) FROM projects_groups')->fetchColumn() === 1, 'Default assignment does not grant project membership');
    check($controller->getById($request, new Response(), ['id' => $id])->getStatusCode() === 200, 'Creator can immediately fetch the task');
    check($tasks->getById($id)['creator_name'] === 'Creator', 'Task detail includes the creator name');
    $listed = json_decode((string) $controller->getAllByUser($request, new Response())->getBody(), true);
    check(count($listed) === 1 && (int) $listed[0]['container_id'] === 1, 'New task appears in the correct container in the API list');
    check($listed[0]['group_ids'] === '21' && $listed[0]['group_names'] === 'Personal user 2', 'List includes the personal assignment');
    check($tasks->getById($id)['group_ids'] === '21', 'Single task includes the personal assignment');
    check((int) $groups->getGroupsForTask($id)[0]['personal_user_id'] === 2, 'Group endpoint includes the default assignee');
    check($subtasks->accessibleTask($id, 2) !== null && $attachments->accessibleTask($id, 2) !== null, 'Creator can access subtasks and attachments');
    check($tasks->isVisibleToUser($id, 1), 'Project owner retains access');
    check($tasks->canDelete($id, 1), 'Project owner can delete a task in their project');
    check(!$tasks->canDelete($id, 3), 'Other project members cannot delete tasks');
    check(!$tasks->isVisibleToUser($id, 3), 'Unassigned project member cannot read a private task');
    check($controller->getById($request->withAttribute('user_id', 4), new Response(), ['id' => $id])->getStatusCode() === 404, 'Stranger cannot read task');
    check($tasks->getAllByUser(4) === [], 'Stranger cannot see task in the list');
    check($controller->create($request->withAttribute('user_id', 4)->withParsedBody($payload), new Response())->getStatusCode() === 404, 'Stranger cannot create in the project');
    $containers = new ContainerController(new Container($db));
    check($containers->getOne($request, new Response(), ['id' => 1])->getStatusCode() === 200, 'Project member can open the container');
    check($containers->getOne($request->withAttribute('user_id', 4), new Response(), ['id' => 1])->getStatusCode() === 404, 'Stranger cannot open container');
    check($containers->update($request->withParsedBody(['title' => 'No']), new Response(), ['id' => 1])->getStatusCode() === 403, 'Read access does not grant container management');

    $db->exec('INSERT INTO users_groups VALUES (4, 21)');
    check(!$tasks->isVisibleToUser($id, 4), 'Membership in another creator\'s personal group cannot bypass the project allowlist');
    check($tasks->getAllByUser(4) === [], 'List also rejects unrelated personal-group members');
    check($subtasks->accessibleTask($id, 4) === null && $attachments->accessibleTask($id, 4) === null, 'Nested resources also reject unrelated personal-group members');
    check(!$groups->assignGroup($id, 22), 'An unrelated personal group cannot be assigned');
    check($groups->assignGroup($id, 10), 'Normal project group can still be assigned');
    check($tasks->isVisibleToUser($id, 3), 'Assigned project group grants task access');
    $assignedIds = explode(',', $tasks->getAllByUser(3)[0]['group_ids']);
    check($assignedIds === ['10', '21'], 'Group member receives complete assignment metadata');

    $response = $controller->create($request->withParsedBody(['title' => 'Own task', 'container_id' => 2]), new Response());
    check($response->getStatusCode() === 201, 'Own project without any project groups supports task creation');
    check(count($tasks->getAllByUser(2)) === 2, 'Own and shared tasks are both listed');

    $before = (int) $db->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
    $db->exec('DELETE FROM users_groups WHERE user_id = 2 AND groups_id = 21');
    try {
        $controller->create($request->withParsedBody($payload), new Response());
        throw new LogicException('Creation must fail when personal membership is missing');
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), 'Persoenliche Gruppe'), 'Missing membership surfaces an explicit error');
    }
    check((int) $db->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === $before, 'Failed assignment rolls back task creation');
    check(!$db->inTransaction(), 'Failed creation closes the transaction');
    $db->exec('DELETE FROM groups WHERE id = 21');
    try {
        $tasks->create(array_replace($payload, ['created_by' => 2]));
        throw new LogicException('Creation must fail when personal group is missing');
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), 'Persoenliche Gruppe'), 'Missing group surfaces an explicit error');
    }
    check((int) $db->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === $before, 'Missing group also rolls back the task');

    $db->exec("INSERT INTO groups (id, name, personal_user_id) VALUES (21, 'Personal user 2', 2);
        INSERT INTO users_groups VALUES (2, 21)");
    $deleteTaskResponse = $controller->create(
        $request->withParsedBody(['title' => 'Task for delete authorization', 'container_id' => 1]),
        new Response()
    );
    check($deleteTaskResponse->getStatusCode() === 201, 'Task for delete authorization is created');
    $deleteTaskId = (int) json_decode((string) $deleteTaskResponse->getBody(), true)['id'];
    check(
        $controller->delete($request->withAttribute('user_id', 1), new Response(), ['id' => (string) $deleteTaskId])->getStatusCode() === 200,
        'Project owner can delete another user\'s task'
    );
    $db->exec("INSERT INTO groups_tasks (task_id, group_id) VALUES ($deleteTaskId, 10)");
    $foreignDeleteResponse = $controller->delete(
        $request->withAttribute('user_id', 3),
        new Response(),
        ['id' => (string) $deleteTaskId]
    );
    check(
        $foreignDeleteResponse->getStatusCode() === 404,
        'Already deleted task is hidden from other users'
    );
    $foreignTaskResponse = $controller->create(
        $request->withParsedBody(['title' => 'Shared task for delete authorization', 'container_id' => 1]),
        new Response()
    );
    $foreignTaskId = (int) json_decode((string) $foreignTaskResponse->getBody(), true)['id'];
    $db->exec("INSERT INTO groups_tasks (task_id, group_id) VALUES ($foreignTaskId, 10)");
    $foreignDeleteResponse = $controller->delete(
        $request->withAttribute('user_id', 3),
        new Response(),
        ['id' => (string) $foreignTaskId]
    );
    check(
        $foreignDeleteResponse->getStatusCode() === 403,
        'Visible task cannot be deleted by another user'
    );
    check(
        json_decode((string) $foreignDeleteResponse->getBody(), true)['error'] ===         'Du darfst diese Task nicht löschen. Das dürfen nur der Ersteller, der Container-Inhaber oder der Projekt-Owner.',
        'Delete denial contains a friendly message'
    );
    check($controller->delete($request, new Response(), ['id' => (string) $foreignTaskId])->getStatusCode() === 200, 'Task creator can delete their own task');
    check(
        (int) $db->query("SELECT deleted_by FROM tasks WHERE id = $foreignTaskId")->fetchColumn() === 2,
        'Task deletion records the creator'
    );
    check(
        $controller->delete($request, new Response(), ['id' => (string) $foreignTaskId])->getStatusCode() === 404,
        'Already deleted task cannot be deleted again'
    );
    $db->exec("INSERT INTO containers VALUES (3, 2, 'Container owned by user 1', 1, NULL)");
    $containerTaskResponse = $controller->create(
        $request->withParsedBody(['title' => 'Task for container owner', 'container_id' => 3]),
        new Response()
    );
    $containerTaskId = (int) json_decode((string) $containerTaskResponse->getBody(), true)['id'];
    $db->exec("INSERT INTO groups (id, name, project_id) VALUES (30, 'Project 2 members', 2);
        INSERT INTO projects_groups VALUES (2, 30);
        INSERT INTO users_groups VALUES (1, 30)");
    check(!$tasks->isVisibleToUser($containerTaskId, 1), 'Container owner need not have regular task visibility');
    check($tasks->canDelete($containerTaskId, 1), 'Container owner can delete tasks in their container');
    check(
        $controller->delete($request->withAttribute('user_id', 1), new Response(), ['id' => (string) $containerTaskId])->getStatusCode() === 200,
        'Container owner can delete task despite lacking regular visibility'
    );
    $db->exec('UPDATE containers SET deleted_at = NOW() WHERE id = 1');
    check(!$tasks->isVisibleToUser($id, 1) && count($tasks->getAllByUser(1)) === 0, 'Deleted container hides tasks in detail and list');
    check(!$tasks->isVisibleToUser($id, 2) && count($tasks->getAllByUser(2)) === 1, 'Personal assignment cannot bypass a deleted container');
    check($subtasks->accessibleTask($id, 1) === null && $attachments->accessibleTask($id, 1) === null, 'Deleted container hides nested resources');
    check($controller->create($request->withParsedBody($payload), new Response())->getStatusCode() === 404, 'Deleted container rejects creation');
    $db->exec('UPDATE containers SET deleted_at = NULL WHERE id = 1; UPDATE projects SET archived_at = NOW() WHERE id = 1');
    check(!$tasks->isVisibleToUser($id, 1) && count($tasks->getAllByUser(1)) === 0, 'Archived project hides tasks in detail and list');
    check(!$tasks->isVisibleToUser($id, 2) && count($tasks->getAllByUser(2)) === 1, 'Personal assignment cannot bypass project archival');
    check($subtasks->accessibleTask($id, 2) === null && $attachments->accessibleTask($id, 2) === null, 'Archived project hides nested resources from creator');
    check($controller->create($request->withParsedBody($payload), new Response())->getStatusCode() === 404, 'Archived project rejects creation');
    echo "Task creation tests passed\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach (array_reverse($createdTables) as $name) {
        $db->exec("DROP TEMPORARY TABLE `$name`");
    }
}
