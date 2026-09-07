<?php

namespace BienenPlan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiController
{
    public function index(Request $request, Response $response): Response
    {
        $data = [
            'name' => 'BienenPlan API',
            'version' => '1.0.0',
            'status' => 'online',
            'description' => 'REST API für die BienenPlan-Anwendung',
            'endpoints' => [
                'POST /api/register' => 'Benutzer registrieren',
                'POST /api/login' => 'Benutzer anmelden',
                'GET /api/tasks' => 'Alle Aufgaben abrufen',
                'GET /api/tasks/{id}' => 'Eine Aufgabe abrufen',
                'POST /api/tasks' => 'Aufgabe erstellen',
                'PUT /api/tasks/{id}' => 'Aufgabe aktualisieren',
                'DELETE /api/tasks/{id}' => 'Aufgabe löschen',
            ]
        ];

        $response->getBody()->write(
            json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            )
        );

        return $response
            ->withHeader('Content-Type', 'application/json');
    }
}