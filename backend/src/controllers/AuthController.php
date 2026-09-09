<?php
namespace BienenPlan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use BienenPlan\Models\User;
use BienenPlan\Services\JwtService;

class AuthController {
    private User $userModel;
    private JwtService $jwtService;

    public function __construct(User $userModel, JwtService $jwtService) {
        $this->userModel = $userModel;
        $this->jwtService = $jwtService;
    }

    public function register(Request $request, Response $response): Response {
        $data = $request->getParsedBody(); # JSON-Body zu Array

        // Frontend sollte die Eingaben ebenfalls validieren
        if (!is_array($data) || empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            $response->getBody()->write(json_encode(['error' => 'name, email und password sind erforderlich']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        } 

        try {
            $result = $this->userModel->create($data['name'], $data['email'], $data['password']);
        } catch (\PDOException $e) { //TODO Exception $e abfangen und entsprechende Fehlermeldung zurückgeben mit passende Statuscode
            $response->getBody()->write(json_encode(['error' => 'E-Mail-Adresse ist bereits registriert', 'details' => $e->getMessage()])); 
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }
        
        $response->getBody()->write(json_encode([
            'message' => 'User registriert',
            'user_id' => $result['user_id'],
            'personal_group_id' => $result['personal_group_id']
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    public function login(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        $user = $this->userModel->findByEmail($data['email']);
# 
        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            $response->getBody()->write(json_encode(['error' => 'Ungültige Anmeldedaten']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $token = $this->jwtService->generateToken($user['id'], $user['email']);

        $response->getBody()->write(json_encode([
            'token' => $token,
            'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}