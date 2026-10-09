<?php
require_once __DIR__ ."/../vendor/autoload.php";
use Slim\Factory\AppFactory;
$app = AppFactory::create();
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

// Preflight OPTIONS-Anfragen abfangen
$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});
$app->run();