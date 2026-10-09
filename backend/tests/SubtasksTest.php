<?php

require __DIR__ . '/../vendor/autoload.php';

use BienenPlan\Controllers\SubtaskController;
use BienenPlan\Controllers\ProjectController;
use BienenPlan\Models\Container;
use BienenPlan\Models\Group;
use BienenPlan\Models\Project;
use BienenPlan\Models\Subtask;
use BienenPlan\Models\Task;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, deleted_at TEXT, is_admin INTEGER DEFAULT 0);
    INSERT INTO users (id) VALUES (1), (2), (3), (4);
    CREATE TABLE projects (id INTEGER PRIMARY KEY, created_by INTEGER, archived_at TEXT);
    CREATE TABLE containers (id INTEGER PRIMARY KEY, project_id INTEGER, created_by INTEGER, deleted_at TEXT);
    CREATE TABLE tasks (id INTEGER PRIMARY KEY, container_id INTEGER, deleted_at TEXT, created_by INTEGER);
    CREATE TABLE groups (id INTEGER PRIMARY KEY, personal_user_id INTEGER, project_id INTEGER, is_global INTEGER DEFAULT 0);
    CREATE TABLE groups_tasks (task_id INTEGER, group_id INTEGER);
    CREATE TABLE projects_groups (project_id INTEGER, group_id INTEGER);
    CREATE TABLE users_groups (user_id INTEGER, groups_id INTEGER);
    CREATE TABLE subtasks (id INTEGER PRIMARY KEY, task_id INTEGER, title TEXT, completed INTEGER DEFAULT 0, created_by INTEGER, deleted_at TEXT, deleted_by INTEGER);
    INSERT INTO projects VALUES (1, 1, NULL);
    INSERT INTO containers VALUES (1, 1, 2, NULL);
    INSERT INTO tasks VALUES (1, 1, NULL, 1), (2, 1, NULL, 1), (3, 1, NULL, 4);
    INSERT INTO groups VALUES (1, NULL, 1, 0);
    INSERT INTO groups_tasks VALUES (1, 1);
    INSERT INTO projects_groups VALUES (1, 1);
    INSERT INTO users_groups VALUES (3, 1), (4, 1);
    INSERT INTO groups_tasks VALUES (3, 1);
    INSERT INTO subtasks VALUES (20, 1, "Legacy", 0, NULL, NULL, NULL), (21, 1, "Member-created", 0, 3, NULL, NULL),
        (22, 3, "Created by another user", 0, 3, NULL, NULL);');
$model = new Subtask($db);
$controller = new SubtaskController($model);
$request = (new ServerRequestFactory())->createServerRequest('POST', '/')->withAttribute('user_id', 1);
$args = ['taskId' => '1'];
$response = $controller->create($request->withParsedBody(['title' => ' New ']), new Response(), $args);
check($response->getStatusCode() === 201, 'Project owner can create');
$item = json_decode((string) $response->getBody(), true);
check($item['title'] === 'New' && $item['completed'] === false && $item['created_by'] === 1, 'Creation normalizes fields');
$args['subtaskId'] = (string) $item['id'];
$archiveRace = new class($db) extends Subtask {
    public function __construct(private PDO $database) { parent::__construct($database); }
    public function accessibleTask(int $taskId, int $userId): ?array {
        $task = parent::accessibleTask($taskId, $userId);
        if ($task !== null) {
            $this->database->exec('UPDATE projects SET archived_at = CURRENT_TIMESTAMP WHERE id = 1');
        }
        return $task;
    }
};
$raceController = new SubtaskController($archiveRace);
$beforeRace = count($model->byTask(1));
check($raceController->create($request->withParsedBody(['title' => 'Denied']), new Response(), $args)->getStatusCode() === 409, 'Subtask creation rejects archival after initial access check');
check(count($model->byTask(1)) === $beforeRace, 'Denied subtask creation inserts nothing');
$db->exec('UPDATE projects SET archived_at = NULL WHERE id = 1');
check($raceController->update($request->withParsedBody(['title' => 'Denied', 'completed' => true]), new Response(), $args)->getStatusCode() === 409, 'Subtask edit rejects archival after initial access check');
check($model->byId(1, $item['id'])['title'] === 'New' && !$model->byId(1, $item['id'])['completed'], 'Denied subtask edit changes neither title nor checkbox');
$db->exec('UPDATE projects SET archived_at = NULL WHERE id = 1');
check($raceController->update($request->withParsedBody(['completed' => true]), new Response(), $args)->getStatusCode() === 409, 'Checkbox-only update also rejects concurrent archival');
$db->exec('UPDATE projects SET archived_at = NULL WHERE id = 1');
check($raceController->delete($request, new Response(), $args)->getStatusCode() === 409, 'Subtask deletion rejects archival after initial access check');
check($model->byId(1, $item['id']) !== null, 'Denied subtask deletion retains the item');
$db->exec('UPDATE projects SET archived_at = NULL WHERE id = 1');
$member = $request->withAttribute('user_id', 3);
check($controller->create($member->withParsedBody(['title' => 'No']), new Response(), $args)->getStatusCode() === 403, 'Members cannot create');
$taskCreator = $request->withAttribute('user_id', 4);
$taskCreatorIndex = json_decode((string) $controller->index($taskCreator, new Response(), ['taskId' => '3'])->getBody(), true);
check($taskCreatorIndex['can_create'] === true, 'Task creator can create subtasks');
check($taskCreatorIndex['subtasks'][0]['can_delete'] === true && $taskCreatorIndex['subtasks'][0]['can_edit'] === false, 'Task creator can delete but not edit another users subtask');
$taskCreatorCreate = $controller->create($taskCreator->withParsedBody(['title' => 'Creator subtask']), new Response(), ['taskId' => '3']);
check($taskCreatorCreate->getStatusCode() === 201, 'Task creator can create');
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
check($controller->update($taskCreator->withParsedBody(['title' => 'Not permitted']), new Response(), ['taskId' => '3', 'subtaskId' => '22'])->getStatusCode() === 403, 'Task creator cannot edit another users subtask');
check($controller->delete($taskCreator, new Response(), ['taskId' => '3', 'subtaskId' => '22'])->getStatusCode() === 200, 'Task creator can delete another users subtask');
check($controller->update($request->withParsedBody(['title' => 'Owner edit']), new Response(), ['taskId' => '1', 'subtaskId' => '20'])->getStatusCode() === 200, 'Owner can edit legacy item');
check($controller->delete($request->withAttribute('user_id', 1), new Response(), $args)->getStatusCode() === 200, 'Project owner can delete');
check($model->byId(1, $item['id']) === null, 'Deleted subtask hidden');
check((int) $db->query('SELECT deleted_by FROM subtasks WHERE id = ' . $item['id'])->fetchColumn() === 1, 'Soft-delete records actor');
$db->exec('UPDATE tasks SET deleted_at = CURRENT_TIMESTAMP WHERE id = 1');
check($controller->index($request, new Response(), $args)->getStatusCode() === 404, 'Deleted parent hides subtasks');
check($controller->update($request->withParsedBody(['completed' => true]), new Response(), ['taskId' => '1', 'subtaskId' => '20'])->getStatusCode() === 404, 'Deleted parent blocks mutations');

$projectDb = new PDO('sqlite::memory:');
$projectDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$projectDb->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT, is_admin INTEGER DEFAULT 0);
    CREATE TABLE groups (id INTEGER PRIMARY KEY, name TEXT, personal_user_id INTEGER, created_at TEXT, project_id INTEGER, is_global INTEGER DEFAULT 0);
    CREATE TABLE users_groups (user_id INTEGER, groups_id INTEGER);
    CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT, created_by INTEGER, created_at TEXT, archived_at TEXT);
    CREATE TABLE projects_groups (project_id INTEGER, group_id INTEGER);
    CREATE TABLE containers (id INTEGER PRIMARY KEY, project_id INTEGER, title TEXT, created_by INTEGER, deleted_at TEXT);
    CREATE TABLE tasks (id INTEGER PRIMARY KEY, container_id INTEGER, created_by INTEGER, title TEXT, deleted_at TEXT);
    CREATE TABLE groups_tasks (task_id INTEGER, group_id INTEGER);
    INSERT INTO users (id, name) VALUES (1, "Owner"), (2, "Member"), (3, "Stranger");
    INSERT INTO groups VALUES (10, "Team", NULL, "now", 20, 0), (11, "Other", NULL, "now", NULL, 1);
    INSERT INTO users_groups VALUES (2, 10), (3, 11);
    INSERT INTO projects VALUES (20, "Project", 1, "now", NULL);
    INSERT INTO projects_groups VALUES (20, 10);
    INSERT INTO containers VALUES (30, 20, "Empty", 1, NULL), (31, 20, "Work", 1, NULL);
    INSERT INTO tasks VALUES (40, 31, 1, "Team task", NULL), (41, 31, 1, "Other task", NULL);
    INSERT INTO groups_tasks VALUES (40, 10), (41, 11);');

$project = new Project($projectDb);
check(count($project->getAll(2)) === 1, 'Project members can see projects without assigned tasks');
check(count($project->getAll(3)) === 0, 'Unrelated users cannot see projects');
check($project->isOwner(20, 1), 'Project owner is recognized');
check(!$project->isOwner(20, 2), 'Project member is not owner');
$containerModel = new Container($projectDb);
check(count($containerModel->getAll(2)) === 2, 'Project members can see all containers');
check($containerModel->isVisibleToUser(30, 2), 'Member can open an empty project container');
check(!$containerModel->isVisibleToUser(30, 3), 'Unrelated user cannot open project container');
check(!$containerModel->canManage(30, 2), 'Project member cannot manage containers');
check($containerModel->canManage(30, 1), 'Project owner can manage containers');
$taskModel = new Task($projectDb);
check($taskModel->isVisibleToUser(40, 2), 'Member can see tasks assigned to their project group');
check(!$taskModel->isVisibleToUser(41, 2), 'Member cannot see tasks assigned to another group');
check(!$taskModel->isVisibleToUser(41, 3), 'Group outside the project cannot grant task access');
check($taskModel->isVisibleToUser(41, 1), 'Project owner can see every project task');
$groupModel = new Group($projectDb);
check(!$groupModel->assignGroup(41, 11, 1), 'A non-project group cannot be assigned to a task');
$projectController = new ProjectController($project);
$ownerRequest = (new ServerRequestFactory())->createServerRequest('POST', '/')->withAttribute('user_id', 1);
$memberRequest = $ownerRequest->withAttribute('user_id', 2);
check($projectController->addGroup($memberRequest, new Response(), ['id' => '20', 'groupId' => '11'])->getStatusCode() === 403, 'Only project owner can add groups');
check($projectController->addGroup($ownerRequest, new Response(), ['id' => '20', 'groupId' => '11'])->getStatusCode() === 201, 'Project owner can add existing groups');
check($groupModel->assignGroup(41, 11, 1), 'Project group can be assigned to a task');
check($projectController->removeGroup($ownerRequest, new Response(), ['id' => '20', 'groupId' => '11'])->getStatusCode() === 200, 'Project owner can remove groups');
check(!$taskModel->isVisibleToUser(41, 3), 'Removing a project group removes its task access');
$createdGroup = $projectController->createGroup(
    $ownerRequest->withParsedBody(['name' => 'New team', 'user_ids' => [3]]),
    new Response(),
    ['id' => '20'],
);
check($createdGroup->getStatusCode() === 201, 'Project owner can create and attach a group');
check((int) $projectDb->query('SELECT COUNT(*) FROM projects_groups WHERE project_id = 20 AND group_id = 12')->fetchColumn() === 1, 'New group is attached to project atomically');
echo "Subtask tests passed\n";