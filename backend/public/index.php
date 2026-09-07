<?php
require_once __DIR__ .'/../bootstrap.php';


require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use BienenPlan\Config\Database;
use BienenPlan\Models\User;
use BienenPlan\Models\Task;
use BienenPlan\Services\JwtService;
use BienenPlan\Controllers\AuthController;
use BienenPlan\Controllers\TaskController;
use BienenPlan\Middleware\AuthMiddleware;
use BienenPlan\Middleware\CorsMiddleware;

// 1. Manuelle Instanziierung der Basis-Dienste
$pdo = Database::getConnection();
$jwtService = new JwtService();

// 2. Manuelle Instanziierung der Models
$userModel = new User($pdo);
$taskModel = new Task($pdo);

// 3. Manuelle Instanziierung der Controller & Middleware
$authController = new AuthController($userModel, $jwtService);
$taskController = new TaskController($taskModel);
$authMiddleware = new AuthMiddleware($jwtService); 
$corsMiddleware = new CorsMiddleware();

// 4. Slim App erstellen
$app = AppFactory::create();
$app->setBasePath('/BienenPlan/backend/public');

// Middlewares hinzufügen (Reihenfolge ist wichtig!)
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add($corsMiddleware);

// 5. Öffentliche Routen
$app->post('/api/register', [$authController, 'register']);
$app->post('/api/login', [$authController, 'login']);


// 6. Geschützte Routen
// Routen in der geschützten Gruppe registrieren
$app->group('/api', function ($group) use ($taskController) {
    $group->get('/tasks', [$taskController, 'getAll']);
    $group->get('/tasks/{id}', [$taskController, 'getById']);
    $group->post('/tasks', [$taskController, 'create']);
    $group->put('/tasks/{id}', [$taskController, 'update']);
    $group->delete('/tasks/{id}', [$taskController, 'delete']);
})->add($authMiddleware);

$app->run();