// Routen in der geschützten Gruppe registrieren
$app->group('/api', function ($group) use ($taskController, $containerController) {
    // Tasks
    $group->get('/tasks', [$taskController, 'getAll']);
    $group->post('/tasks', [$taskController, 'create']);

    // Containers (CRUD)
    $group->get('/containers', [$containerController, 'getAll']);
    $group->get('/containers/{id}', [$containerController, 'getOne']);
    $group->post('/containers', [$containerController, 'create']);
    $group->put('/containers/{id}', [$containerController, 'update']);
    $group->delete('/containers/{id}', [$containerController, 'delete']);

})->add($authMiddleware);