<?php

require __DIR__ . '/../vendor/autoload.php';

use BienenPlan\Controllers\SubtaskController;
use BienenPlan\Models\Subtask;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, created_by INTEGER, archived_at TEXT);
    CREATE TABLE containers (id INTEGER PRIMARY KEY, project_id INTEGER, created_by INTEGER, deleted_at TEXT);
    CREATE TABLE tasks (id INTEGER PRIMARY KEY, container_id INTEGER, deleted_at TEXT);
    CREATE TABLE groups_tasks (task_id INTEGER, group_id INTEGER);
    CREATE TABLE users_groups (user_id INTEGER, groups_id INTEGER);
    CREATE TABLE subtasks (id INTEGER PRIMARY KEY, task_id INTEGER, title TEXT, completed INTEGER DEFAULT 0, created_by INTEGER, deleted_at TEXT, deleted_by INTEGER);
    INSERT INTO projects VALUES (1, 1, NULL);
    INSERT INTO containers VALUES (1, 1, 2, NULL);
    INSERT INTO tasks VALUES (1, 1, NULL), (2, 1, NULL);
    INSERT INTO groups_tasks VALUES (1, 1);
    INSERT INTO users_groups VALUES (3, 1);
    INSERT INTO subtasks VALUES (20, 1, "Legacy", 0, NULL, NULL, NULL), (21, 1, "Member-created", 0, 3, NULL, NULL);');
$model = new Subtask($db);
$controller = new SubtaskController($model);
$request = (new ServerRequestFactory())->createServerRequest('POST', '/')->withAttribute('user_id', 2);
$args = ['taskId' => '1'];
$response = $controller->create($request->withParsedBody(['title' => ' New ']), new Response(), $args);
check($response->getStatusCode() === 201, 'Container owner can create');
$item = json_decode((string) $response->getBody(), true);
check($item['title'] === 'New' && $item['completed'] === false && $item['created_by'] === 2, 'Creation normalizes fields');
$args['subtaskId'] = (string) $item['id'];
$member = $request->withAttribute('user_id', 3);
check($controller->create($member->withParsedBody(['title' => 'No']), new Response(), $args)->getStatusCode() === 403, 'Members cannot create');
check($controller->update($member->withParsedBody(['completed' => true]), new Response(), $args)->getStatusCode() === 200, 'Members can complete');
check($model->byId(1, $item['id'])['title'] === 'New', 'Checkbox update preserves title');
check($controller->update($member->withParsedBody(['title' => 'No', 'completed' => false]), new Response(), $args)->getStatusCode() === 403, 'Mixed requests cannot bypass title permissions');
check($controller->update($request->withParsedBody(['completed' => 'true']), new Response(), $args)->getStatusCode() === 400, 'Boolean must be typed');
check($controller->update($request->withParsedBody(['title' => str_repeat('a', 101)]), new Response(), $args)->getStatusCode() === 400, 'Title length enforced');
check($controller->update($request->withParsedBody(['title' => " \n "]), new Response(), $args)->getStatusCode() === 400, 'Blank title rejected');
check($controller->update($request->withParsedBody(['task_id' => 2]), new Response(), $args)->getStatusCode() === 400, 'Task reassignment rejected');
check($controller->update($request->withParsedBody([]), new Response(), $args)->getStatusCode() === 400, 'Empty update rejected');
check($controller->index($request->withAttribute('user_id', 99), new Response(), $args)->getStatusCode() === 404, 'Strangers cannot read');
check($controller->update($request->withParsedBody(['completed' => true]), new Response(), ['taskId' => '2', 'subtaskId' => $args['subtaskId']])->getStatusCode() === 404, 'Nested IDs must match');
check($controller->delete($member, new Response(), $args)->getStatusCode() === 403, 'Non-creator member cannot delete');
check($controller->update($member->withParsedBody(['title' => 'Creator edit']), new Response(), ['taskId' => '1', 'subtaskId' => '21'])->getStatusCode() === 200, 'Creator with task access can edit');
check($controller->delete($member, new Response(), ['taskId' => '1', 'subtaskId' => '21'])->getStatusCode() === 200, 'Creator can delete');
check($controller->update($request->withParsedBody(['title' => 'Owner edit']), new Response(), ['taskId' => '1', 'subtaskId' => '20'])->getStatusCode() === 200, 'Owner can edit legacy item');
check($controller->delete($request->withAttribute('user_id', 1), new Response(), $args)->getStatusCode() === 200, 'Project owner can delete');
check($model->byId(1, $item['id']) === null, 'Deleted subtask hidden');
check((int) $db->query('SELECT deleted_by FROM subtasks WHERE id = ' . $item['id'])->fetchColumn() === 1, 'Soft-delete records actor');
$db->exec('UPDATE tasks SET deleted_at = CURRENT_TIMESTAMP WHERE id = 1');
check($controller->index($request, new Response(), $args)->getStatusCode() === 404, 'Deleted parent hides subtasks');
check($controller->update($request->withParsedBody(['completed' => true]), new Response(), ['taskId' => '1', 'subtaskId' => '20'])->getStatusCode() === 404, 'Deleted parent blocks mutations');
echo "Subtask tests passed\n";