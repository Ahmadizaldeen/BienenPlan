<?php
require_once __DIR__ .'/../bootstrap.php';


use BienenPlan\Controllers\GroupController;
use BienenPlan\Models\Group;
use Slim\Factory\AppFactory;
use BienenPlan\Config\Database;
use BienenPlan\Models\User;
use BienenPlan\Models\Task;
use BienenPlan\Models\TaskAttachment;
use BienenPlan\Models\Subtask;
use BienenPlan\Controllers\SubtaskController;
use BienenPlan\Models\Project;
use BienenPlan\Models\Container;

use BienenPlan\Services\JwtService;
use BienenPlan\Controllers\AuthController;
use BienenPlan\Controllers\TaskController;
use BienenPlan\Controllers\TaskAttachmentController;
use BienenPlan\Controllers\ProjectController;
use BienenPlan\Controllers\ContainerController;
use BienenPlan\Controllers\UserController;

use BienenPlan\Middleware\AuthMiddleware;
use BienenPlan\Middleware\CorsMiddleware;
use BienenPlan\Controllers\ApiController;
use BienenPlan\Error\BootstrapErrorHandler;
use Slim\Exception\HttpNotFoundException;
use BienenPlan\Error\NotFoundHandler;

//bootstrapen und Exception Handlen
try{
    //Manuelle Instanziierung der Basis-Dienste
    $pdo = Database::getConnection();
    $jwtService = new JwtService();

    //Manuelle Instanziierung der Models
    $userModel = new User($pdo);
    $taskModel = new Task($pdo);
    $taskAttachmentModel = new TaskAttachment($pdo);
    $subtaskController = new SubtaskController(new Subtask($pdo));
    $groupModel = new Group($pdo);
    $projectModel = new Project($pdo);
    $containerModel = new Container($pdo);

    //Manuelle Instanziierung der Controller & Middleware
    $authController = new AuthController($userModel, $jwtService);
    $attachmentController = new TaskAttachmentController($taskAttachmentModel);
    $taskController = new TaskController($taskModel, $attachmentController);
    $groupController = new GroupController($groupModel, $userModel);
    $projectController = new ProjectController($projectModel);
    $containerController = new ContainerController($containerModel);
    $userController = new UserController($userModel);
    $authMiddleware = new AuthMiddleware($jwtService, $userModel);
    $corsMiddleware = new CorsMiddleware();
    $apiController = new ApiController();
    $notFoundHandler = new NotFoundHandler();
}
catch (Throwable $e){
    BootstrapErrorHandler::handle($e);
}

// Slim App erstellen
$app = AppFactory::create();
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($basePath !== '') {
    $app->setBasePath($basePath);
}

// Middlewares hinzufügen (Reihenfolge ist wichtig!)
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add($corsMiddleware);

$errorMiddleware = $app->addErrorMiddleware(
    false, # Fehlerdetails anzeigen
    true, #Fehler loggen
    true # Details loggen
);
$errorMiddleware->setErrorHandler(HttpNotFoundException::class, $notFoundHandler);

//  Öffentliche Routen
$app->post('/api/register', [$authController, 'register']);
$app->post('/api/login', [$authController, 'login']);
$app->get('/api', [$apiController, 'index']);
$app->get('/', [$apiController, 'index']); # Setup Route
// Geschützte Routen
// Routen in der geschützten Gruppe registrieren
$app->group('/api', function ($group) use ($taskController, $attachmentController, $subtaskController, $groupController, $projectController, $containerController, $authController, $userController) {
    $group->get('/me', [$authController, 'me']);
    $group->post('/me/picture', [$userController, 'uploadPicture']);
    $group->get('/users', [$userController, 'getAll']);

    $group->get('/tasks', [$taskController, 'getAllByUser']);
    $group->get('/tasks/{id}', [$taskController, 'getById']);
    $group->post('/tasks', [$taskController, 'create']);
    $group->put('/tasks/{id}', [$taskController, 'update']);
    $group->delete('/tasks/{id}', [$taskController, 'delete']);
    $group->post('/tasks/{id}/status', [$taskController, 'updateStatus']);
    $group->post('/tasks/{id}/move', [$taskController, 'move']);
    $group->post('/tasks/{id}/attachment', [$taskController, 'uploadAttachment']);
    $group->get('/tasks/{id}/attachments', [$attachmentController, 'index']);
    $group->post('/tasks/{id}/attachments', [$attachmentController, 'upload']);
    $group->get('/tasks/{id}/attachments/{attachmentId}/download', [$attachmentController, 'download']);
    $group->delete('/tasks/{id}/attachments/{attachmentId}', [$attachmentController, 'delete']);
    $group->get('/tasks/{taskId}/subtasks', [$subtaskController, 'index']);
    $group->post('/tasks/{taskId}/subtasks', [$subtaskController, 'create']);
    $group->put('/tasks/{taskId}/subtasks/{subtaskId}', [$subtaskController, 'update']);
    $group->delete('/tasks/{taskId}/subtasks/{subtaskId}', [$subtaskController, 'delete']);

    // Gruppen-Routen
    $group->get('/groups', [$groupController, 'getAllGroups']);
    $group->post('/groups', [$groupController, 'createGroup']);
    $group->post('/groups/{groupId}/addUser/{userId}', [$groupController, 'addUserToGroup']);
    $group->delete('/groups/{groupId}/users/{userId}', [$groupController, 'removeUserFromGroup']);
    $group->get('/tasks/{taskId}/groups', [$groupController, 'getGroupsForTask']);
    $group->get('/groups/{groupId}/users', [$groupController, 'getUsersInGroup']);
    $group->get('/groups/{groupId}/personal-user', [$groupController, 'getPersonalGroupUser']);
    $group->get('/users/{userId}/groups', [$groupController, 'getGroupsForUser']);
    $group->post('/tasks/{taskId}/assign/{groupId}', [$groupController, 'assignGroup']);
    $group->delete('/tasks/{taskId}/groups/{groupId}', [$groupController, 'removeGroup']);

    
    $group->get('/projects', [$projectController, 'getAll']);
    // Keep the literal archive endpoint distinct from the project-ID route.
    $group->get('/projects/archived', [$projectController, 'getArchived']);
    $group->post('/projects/{id}/restore', [$projectController, 'restore']);
    $group->get('/projects/{id}', [$projectController, 'getById']);
    $group->get('/projects/{id}/groups', [$projectController, 'getGroups']);
    $group->post('/projects/{id}/groups', [$projectController, 'createGroup']);
    $group->post('/projects/{id}/groups/{groupId}', [$projectController, 'addGroup']);
    $group->put('/projects/{id}/groups/{groupId}', [$projectController, 'updateGroup']);
    $group->delete('/projects/{id}/groups/{groupId}', [$projectController, 'removeGroup']);
    $group->post('/projects', [$projectController, 'create']);
    $group->put('/projects/{id}', [$projectController, 'update']);
    $group->delete('/projects/{id}', [$projectController, 'delete']);
    
    // Container-Routen
    $group->get('/containers', [$containerController, 'getAll']);
    $group->get('/containers/{id}', [$containerController, 'getOne']);
    $group->post('/containers', [$containerController, 'create']);
    $group->put('/containers/{id}', [$containerController, 'update']);
    $group->delete('/containers/{id}', [$containerController, 'delete']);
})->add($authMiddleware);

$app->run();