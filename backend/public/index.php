<?php
require_once __DIR__ .'/../bootstrap.php';


use BienenPlan\Controllers\GroupController;
use BienenPlan\Models\Group;
use Slim\Factory\AppFactory;
use BienenPlan\Config\Database;
use BienenPlan\Models\User;
use BienenPlan\Models\Task;
use BienenPlan\Models\Project;
use BienenPlan\Models\Container;

use BienenPlan\Services\JwtService;
use BienenPlan\Controllers\AuthController;
use BienenPlan\Controllers\TaskController;
use BienenPlan\Controllers\ProjectController;
use BienenPlan\Controllers\ContainerController;

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
    $groupModel = new Group($pdo);
    $projectModel = new Project($pdo);
    $containerModel = new Container($pdo);

    //Manuelle Instanziierung der Controller & Middleware
    $authController = new AuthController($userModel, $jwtService);
    $taskController = new TaskController($taskModel);
    $groupController = new GroupController($groupModel);
    $projectController = new ProjectController($projectModel);
    $containerController = new ContainerController($containerModel);
    $authMiddleware = new AuthMiddleware($jwtService); 
    $corsMiddleware = new CorsMiddleware();
    $apiController = new ApiController();
    $notFoundHandler = new NotFoundHandler();
}
catch (Throwable $e){
    BootstrapErrorHandler::handle($e);
}

// Slim App erstellen
$app = AppFactory::create();
$app->setBasePath('/BienenPlan/backend/public');

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
$app->group('/api', function ($group) use ($taskController, $groupController, $projectController, $containerController) {
    $group->get('/tasks', [$taskController, 'getAllByUser']);
    $group->get('/tasks/{id}', [$taskController, 'getById']);
    $group->post('/tasks', [$taskController, 'create']);
    $group->put('/tasks/{id}', [$taskController, 'update']);
    $group->delete('/tasks/{id}', [$taskController, 'delete']);
    $group->post('/tasks/{id}/status', [$taskController, 'updateStatus']);
    $group->post('/tasks/{id}/attachment', [$taskController, 'uploadAttachment']);

    // Gruppen-Routen
    $group->get('/groups', [$groupController, 'getAllGroups']);
    $group->post('/groups', [$groupController, 'createGroup']);
    $group->post('/groups/{groupId}/addUser/{userId}', [$groupController, 'addUserToGroup']);
    $group->get('/tasks/{taskId}/groups', [$groupController, 'getGroupsForTask']);
    $group->get('/groups/{groupId}/users', [$groupController, 'getUsersInGroup']);
    $group->get('/users/{userId}/groups', [$groupController, 'getGroupsForUser']);
    $group->post('/tasks/{taskId}/assign/{groupId}', [$groupController, 'assignGroup']);
    $group->delete('/tasks/{taskId}/groups/{groupId}', [$groupController, 'removeGroup']);

    
    $group->get('/projects', [$projectController, 'getAll']);
    $group->get('/projects/{id}', [$projectController, 'getById']);
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