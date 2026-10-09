<?php

require __DIR__ . '/../vendor/autoload.php';

use BienenPlan\Config\Database;
use BienenPlan\Controllers\AuthController;
use BienenPlan\Controllers\ContainerController;
use BienenPlan\Controllers\GroupController;
use BienenPlan\Controllers\ProjectController;
use BienenPlan\Controllers\SubtaskController;
use BienenPlan\Controllers\TaskAttachmentController;
use BienenPlan\Controllers\TaskController;
use BienenPlan\Middleware\AuthMiddleware;
use BienenPlan\Models\Container;
use BienenPlan\Models\Group;
use BienenPlan\Models\Project;
use BienenPlan\Models\Subtask;
use BienenPlan\Models\Task;
use BienenPlan\Models\TaskAttachment;
use BienenPlan\Models\User;
use BienenPlan\Services\AccessService;
use BienenPlan\Services\Env;
use BienenPlan\Services\JwtService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

Env::load();
$db = Database::getConnection();
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$tables = [
    'users' => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), email VARCHAR(255) UNIQUE,
        password_hash VARCHAR(255), picture TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL, is_admin BOOLEAN DEFAULT FALSE',
    'projects' => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), created_by INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, archived_at DATETIME NULL, archived_by INT',
    'groups' => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), personal_user_id INT NULL UNIQUE,
        project_id INT NULL, is_global BOOLEAN DEFAULT FALSE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        name_scope INT GENERATED ALWAYS AS (COALESCE(project_id, 0)) STORED,
        UNIQUE (name_scope, name)',
    'projects_groups' => 'project_id INT, group_id INT, PRIMARY KEY (project_id, group_id)',
    'users_groups' => "user_id INT, groups_id INT, role VARCHAR(20) DEFAULT 'member', PRIMARY KEY (user_id, groups_id)",
    'containers' => 'id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100), project_id INT, created_by INT,
        deleted_at DATETIME NULL, deleted_by INT',
    'tasks' => "id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100), description TEXT, status VARCHAR(20) DEFAULT 'open',
        deadline DATETIME, created_by INT, container_id INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL, deleted_by INT",
    'groups_tasks' => 'task_id INT, group_id INT, PRIMARY KEY (task_id, group_id)',
    'subtasks' => 'id INT AUTO_INCREMENT PRIMARY KEY, task_id INT, title VARCHAR(100), completed BOOLEAN DEFAULT FALSE,
        created_by INT, deleted_at DATETIME NULL, deleted_by INT',
    'task_attachments' => 'id INT AUTO_INCREMENT PRIMARY KEY, task_id INT, uploaded_by INT, original_name VARCHAR(255),
        stored_name VARCHAR(255), mime_type VARCHAR(100), size_bytes INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL',
];
$created = [];
try {
    foreach ($tables as $name => $definition) {
        $db->exec("CREATE TEMPORARY TABLE `$name` ($definition) ENGINE=InnoDB");
        $created[] = $name;
    }
    $db->exec("INSERT INTO users (id, name, is_admin) VALUES
        (1, 'Owner', 0), (2, 'Container owner', 0), (3, 'Task owner', 0),
        (4, 'Member', 0), (5, 'Stranger', 0), (6, 'Admin', 1);
        INSERT INTO projects (id, name, created_by) VALUES (1, 'One', 1), (2, 'Two', 5);
        INSERT INTO groups (id, name, project_id, is_global, personal_user_id) VALUES
        (10, 'Local', 1, 0, NULL), (11, 'Local', 2, 0, NULL),
        (12, 'Global', NULL, 1, NULL), (13, 'Unclassified', NULL, 0, NULL),
        (14, 'Personal user 3', NULL, 0, 3);
        INSERT INTO projects_groups VALUES (1, 10), (2, 11);
        INSERT INTO users_groups (user_id, groups_id) VALUES (2, 10), (3, 10), (4, 10), (3, 14), (5, 11);
        INSERT INTO containers (id, title, project_id, created_by) VALUES
        (1, 'Work', 1, 2), (2, 'Empty', 1, 2), (3, 'Other', 2, 5);
        INSERT INTO tasks (id, title, created_by, container_id) VALUES
        (1, 'Private', 3, 1), (2, 'Shared', 3, 1), (3, 'Legacy', NULL, 1);
        INSERT INTO groups_tasks VALUES (1, 14), (2, 10);
        INSERT INTO subtasks (id, task_id, title, created_by) VALUES (1, 2, 'Member item', 4);
        INSERT INTO task_attachments (id, task_id, uploaded_by, original_name) VALUES (1, 2, 4, 'test.txt');");
    $attachmentFixture = $db->prepare('UPDATE task_attachments SET stored_name = :name WHERE id = 1');
    $attachmentFixture->execute(['name' => bin2hex(random_bytes(16)) . '.txt']);

    $access = new AccessService($db);
    $tasks = new Task($db);
    $projects = new Project($db);
    $groups = new Group($db);
    $users = new User($db);
    $containers = new Container($db);
    $attachments = new TaskAttachment($db);
    $taskController = new TaskController($tasks, new TaskAttachmentController($attachments));
    $groupController = new GroupController($groups, $users);
    $projectController = new ProjectController($projects);
    $containerController = new ContainerController($containers);
    $subtaskController = new SubtaskController(new Subtask($db));
    $attachmentController = new TaskAttachmentController($attachments);
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/')->withAttribute('user_id', 1);

    check($access->canViewTask(6, 1) && $access->canDeleteTask(6, 1), 'Admin has cross-project task access');
    check(count($tasks->getAllByUser(6)) === 3, 'Admin list includes legacy tasks without creator');
    check(count($projects->getAll(6)) === 2 && count($containers->getAll(6)) === 3, 'Admin sees all active projects and containers');
    check(!$access->canViewTask(2, 1) && $access->canDeleteTask(2, 1), 'Container owner may delete but not read private task');
    check($access->canViewTask(3, 1) && $access->canEditTask(3, 1), 'Creator sees and edits own unassigned task');
    check(!$access->canViewTask(4, 1) && $access->canViewTask(4, 2), 'Member sees only assigned tasks');
    check(!$access->canViewProject(5, 1), 'Other project owner has no foreign project access');
    check($access->canManageGroup(1, 10) && !$access->canManageGroup(1, 12), 'Project owner manages only local membership');
    check(!$access->canViewGroup(1, 11) && $access->canViewGroup(1, 12), 'Foreign local group hidden, global group selectable');
    check(!$access->canViewGroup(1, 13), 'Unclassified legacy group is not published globally');
    $visibleGroups = array_column($groups->getAllGroups(1), 'id');
    check(in_array(10, $visibleGroups) && in_array(12, $visibleGroups)
        && !in_array(11, $visibleGroups) && !in_array(13, $visibleGroups), 'Group list respects local/global scope');
    check($groupController->getUsersInGroup($request, new Response(), ['groupId' => '11'])->getStatusCode() === 404, 'Foreign local member list is hidden');
    check($groups->getGroupsForUser(5, 1) === [], 'Foreign local memberships not exposed by user group endpoint');
    check(!$projects->addGroup(1, 11) && !$projects->addGroup(1, 14), 'Foreign local and personal groups cannot be linked');
    check($projects->addGroup(1, 12), 'Owner can select global group for project');
    check(!$groups->assignGroup(1, 11) && !$groups->assignGroup(1, 14), 'Only eligible project groups can be manually assigned');

    $member = $request->withAttribute('user_id', 4);
    check($taskController->update($member->withParsedBody(['title' => 'No']), new Response(), ['id' => '2'])->getStatusCode() === 403, 'Member cannot edit task content');
    check($taskController->updateStatus($member->withParsedBody(['status' => 'done']), new Response(), ['id' => '2'])->getStatusCode() === 200, 'Member can change status');
    check($taskController->create($request->withAttribute('user_id', 5)->withParsedBody([
        'title' => 'No',
        'container_id' => 1,
    ]), new Response())->getStatusCode() === 404, 'Stranger cannot create task');
    check($containerController->create($request->withAttribute('user_id', 5)->withParsedBody([
        'title' => 'No',
        'project_id' => 1,
    ]), new Response())->getStatusCode() === 404, 'Stranger cannot create container');
    check($containerController->create($member->withParsedBody([
        'title' => 'Member container',
        'project_id' => 1,
        'created_by' => 6,
    ]), new Response())->getStatusCode() === 201, 'Member can create container');
    check((int) $db->query("SELECT created_by FROM containers WHERE title = 'Member container'")->fetchColumn() === 4, 'Container ownership comes from authenticated user');
    check($groupController->assignGroup($member, new Response(), ['taskId' => '2', 'groupId' => '12'])->getStatusCode() === 403, 'Member cannot change assignments');
    check($groupController->createGroup($request->withParsedBody(['name' => 'No']), new Response(), [])->getStatusCode() === 403, 'Owner cannot create global group');
    check($groupController->createGroup($request->withAttribute('user_id', 6)->withParsedBody(['name' => 'New global']), new Response(), [])->getStatusCode() === 201, 'Admin creates global group');
    check($projectController->createGroup($request->withParsedBody(['name' => 'Local', 'user_ids' => [4]]), new Response(), ['id' => '1'])->getStatusCode() === 409, 'Local names unique within project');
    check($projectController->createGroup($request->withParsedBody(['name' => 'Team', 'user_ids' => [4]]), new Response(), ['id' => '1'])->getStatusCode() === 201, 'Owner creates local group');
    check($groupController->addUserToGroup($request, new Response(), ['groupId' => '12', 'userId' => '4'])->getStatusCode() === 403, 'Owner cannot alter global membership');
    check($groupController->addUserToGroup($request, new Response(), ['groupId' => '10', 'userId' => '5'])->getStatusCode() === 201, 'Owner adds individual to local group');
    check($groupController->removeUserFromGroup($request, new Response(), ['groupId' => '10', 'userId' => '5'])->getStatusCode() === 200, 'Owner removes local member');
    check(!$access->canViewProject(5, 1), 'Removal takes effect immediately');
    check($subtaskController->create($request->withAttribute('user_id', 2)->withParsedBody(['title' => 'No']), new Response(), ['taskId' => '2'])->getStatusCode() === 403, 'Assigned container owner cannot create subtasks');
    check($subtaskController->update($member->withParsedBody(['title' => 'Own item']), new Response(), ['taskId' => '2', 'subtaskId' => '1'])->getStatusCode() === 200, 'Member edits own subtask');
    check($attachmentController->delete($request->withAttribute('user_id', 6), new Response(), ['id' => '2', 'attachmentId' => '1'])->getStatusCode() === 200, 'Admin deletes foreign attachment');

    check($containerController->delete($request->withAttribute('user_id', 2), new Response(), ['id' => '1'])->getStatusCode() === 409, 'Active tasks block container removal');
    check($containerController->delete($member, new Response(), ['id' => '2'])->getStatusCode() === 403, 'Member cannot delete container');
    check($containerController->delete($request->withAttribute('user_id', 2), new Response(), ['id' => '2'])->getStatusCode() === 200, 'Owner soft deletes empty container');
    check((int) $db->query('SELECT deleted_by FROM containers WHERE id = 2')->fetchColumn() === 2, 'Soft deletion retains container and actor');
    check(!$access->canViewContainer(6, 2), 'Even admin cannot use deleted container through normal endpoint');
    $db->exec('UPDATE containers SET deleted_at = NULL WHERE id = 2');
    check($taskController->move($member->withParsedBody(['container_id' => 2]), new Response(), ['id' => '2'])->getStatusCode() === 403, 'Member cannot move task');
    check($taskController->move($request->withParsedBody(['container_id' => 3]), new Response(), ['id' => '2'])->getStatusCode() !== 200, 'Cross-project move denied');
    check($taskController->move($request->withParsedBody(['container_id' => 2]), new Response(), ['id' => '2'])->getStatusCode() === 200, 'Owner moves within project');

    $db->exec('DELETE FROM users_groups WHERE user_id = 3 AND groups_id = 10');
    check(!$access->canViewTask(3, 1) && !$access->canEditTask(3, 1) && !$access->canDeleteTask(3, 1), 'Membership loss overrides task ownership and personal assignment');
    check($tasks->getAllByUser(3) === [] && (int) $tasks->getById(1)['created_by'] === 3, 'Creator remains stored but list access ends');
    check((int) $tasks->getById(1, 6)['creator_has_project_access'] === 0, 'Admin sees creator without project access');
    $adminItems = $tasks->getAllByUser(6);
    foreach ($adminItems as $adminItem) {
        $detail = $tasks->getById((int) $adminItem['id'], 6);
        check($detail !== null && $detail['can_edit'] == $adminItem['can_edit']
            && $detail['can_delete'] == $adminItem['can_delete'], 'List and detail permissions agree');
    }
    $db->exec('DELETE FROM users_groups WHERE user_id = 2 AND groups_id = 10');
    check(!$access->canManageContainer(2, 1) && !$access->canDeleteTask(2, 1), 'Membership loss overrides container ownership');
    $db->exec('INSERT INTO users_groups (user_id, groups_id) VALUES (3, 12)');
    check($access->canViewTask(3, 1), 'Another assigned project group preserves creator access');
    $db->exec('DELETE FROM users_groups WHERE user_id = 3 AND groups_id = 12');

    $jwt = new JwtService();
    $middleware = new AuthMiddleware($jwt, $users);
    $token = $jwt->generateToken(6, 'admin@example.test');
    $authenticated = $request->withHeader('Authorization', 'Bearer ' . $token);
    $handler = new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return (new Response())->withStatus($request->getAttribute('is_admin') ? 204 : 200);
        }
    };
    check($middleware($authenticated, $handler)->getStatusCode() === 204, 'Middleware reads current admin');
    $db->exec('UPDATE users SET is_admin = 0 WHERE id = 6');
    check($middleware($authenticated, $handler)->getStatusCode() === 200 && !$access->canViewTask(6, 1), 'Admin revocation applies with same JWT');
    $db->exec('UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = 6');
    check($middleware($authenticated, $handler)->getStatusCode() === 401, 'Deleted user rejected with existing JWT');
    $authController = new AuthController($users, $jwt);
    check($authController->register($request->withParsedBody([
        'name' => 'New',
        'email' => 'new@example.test',
        'password' => 'test-password',
        'is_admin' => true,
    ]), new Response())->getStatusCode() === 201, 'Registration succeeds');
    check((int) $db->query("SELECT is_admin FROM users WHERE email = 'new@example.test'")->fetchColumn() === 0, 'Registration cannot grant admin');
    $newUser = (int) $db->query("SELECT id FROM users WHERE email = 'new@example.test'")->fetchColumn();
    check($projectController->create($request->withAttribute('user_id', $newUser)->withParsedBody(['name' => 'My project']), new Response())->getStatusCode() === 201, 'Any active user creates project');
    $db->exec('UPDATE projects SET archived_at = CURRENT_TIMESTAMP WHERE id = 1');
    check(!$access->canViewTask(1, 1) && !$access->canManageGroup(1, 10), 'Archived project blocks content and local membership changes');
    $db->exec('UPDATE users SET deleted_at = NULL, is_admin = 1 WHERE id = 6');
    $admin = $request->withAttribute('user_id', 6);
    check($projectController->getArchived($request, new Response())->getStatusCode() === 403, 'Even project owner cannot open admin archive');
    $archiveResponse = $projectController->getArchived($admin, new Response());
    $archive = json_decode((string) $archiveResponse->getBody(), true);
    check($archiveResponse->getStatusCode() === 200 && count($archive) === 1, 'Admin sees archived projects separately');
    check($archive[0]['can_restore'] == 1 && $archive[0]['can_edit'] == 0 && $archive[0]['can_delete'] == 0, 'Archive capabilities are read-only plus restore');
    check($projectController->getById($admin, new Response(), ['id' => '1'])->getStatusCode() === 200, 'Admin opens archived project detail');
    check($projectController->getById($request, new Response(), ['id' => '1'])->getStatusCode() === 404, 'Owner cannot read archived project');
    check(count($projects->getAll(6)) === 2, 'Default project list excludes archive');
    check(count($containers->getAll(6, 1)) === 3 && count($tasks->getAllByUser(6, 1)) === 3, 'Admin reads archive contents using project filter');
    check($containers->getAll(1, 1) === [] && $tasks->getAllByUser(1, 1) === [], 'Project filter does not expose archive to owner');
    check($access->canViewContainer(6, 1) && $access->canViewTask(6, 2), 'Admin has archive detail access');
    $archivedTask = $tasks->getById(2, 6);
    check($archivedTask['can_edit'] == 0 && $archivedTask['can_delete'] == 0
        && $archivedTask['can_change_status'] == 0 && $archivedTask['can_manage_groups'] == 0, 'Archived task capabilities are all read-only');
    check($taskController->updateStatus($admin->withParsedBody(['status' => 'open']), new Response(), ['id' => '2'])->getStatusCode() === 403, 'Admin cannot change archived task status');
    check($taskController->update($admin->withParsedBody(['title' => 'No']), new Response(), ['id' => '2'])->getStatusCode() === 403, 'Admin cannot edit archived task');
    check($taskController->delete($admin, new Response(), ['id' => '2'])->getStatusCode() === 403, 'Admin cannot delete archived task');
    check(!$access->canCreateTask(6, 1) && !$access->canCreateInProject(6, 1)
        && !$access->canManageGroup(6, 10), 'Archive blocks creation and local group membership changes');
    check($containerController->update($admin->withParsedBody(['title' => 'No']), new Response(), ['id' => '1'])->getStatusCode() === 403, 'Archived container cannot be edited');
    check($groupController->assignGroup($admin, new Response(), ['taskId' => '2', 'groupId' => '12'])->getStatusCode() === 403, 'Archived assignments cannot change');
    $archivedSubtasks = $subtaskController->index($admin, new Response(), ['taskId' => '2']);
    $subtaskData = json_decode((string) $archivedSubtasks->getBody(), true);
    check($archivedSubtasks->getStatusCode() === 200 && !$subtaskData['can_create']
        && !$subtaskData['subtasks'][0]['can_complete'], 'Admin sees read-only archived subtasks');
    check($subtaskController->update($admin->withParsedBody(['completed' => true]), new Response(), ['taskId' => '2', 'subtaskId' => '1'])->getStatusCode() === 403, 'Archived checkbox update denied');
    check($subtaskController->delete($admin, new Response(), ['taskId' => '2', 'subtaskId' => '1'])->getStatusCode() === 403, 'Archived subtask deletion denied');
    check($attachmentController->index($admin, new Response(), ['id' => '2'])->getStatusCode() === 200, 'Admin reads archived attachment list');
    check($attachmentController->upload($admin, new Response(), ['id' => '2'])->getStatusCode() === 403, 'Archive upload denied before processing files');
    check($projectController->restore($request, new Response(), ['id' => '1'])->getStatusCode() === 403, 'Owner cannot restore');
    check($projectController->restore($admin, new Response(), ['id' => '99999'])->getStatusCode() === 404, 'Missing restore target returns 404');
    check($projectController->restore($admin, new Response(), ['id' => '1'])->getStatusCode() === 200, 'Admin restores project');
    check($projects->getAll(6, true) === [] && $access->canViewTask(1, 1), 'Restore returns project to normal access');
    check(!$access->canViewTask(3, 1), 'Restore does not recreate revoked membership');
    check($projectController->restore($admin, new Response(), ['id' => '1'])->getStatusCode() === 409, 'Repeated restore returns conflict');
    $db->exec('UPDATE projects SET archived_at = CURRENT_TIMESTAMP, archived_by = 1 WHERE id = 1');
    $db->exec('UPDATE users SET is_admin = 0 WHERE id = 6');
    check($projectController->getArchived($admin, new Response())->getStatusCode() === 403
        && $projectController->restore($admin, new Response(), ['id' => '1'])->getStatusCode() === 403, 'Admin revocation blocks archive and restore immediately');
    echo "Access service tests passed\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    foreach (array_reverse($created) as $name) $db->exec("DROP TEMPORARY TABLE `$name`");
}
