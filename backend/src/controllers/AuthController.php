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
        $this->userModel->create($data['name'], $data['email'], $data['password']);

        $response->getBody()->write(json_encode(['message' => 'User registriert']));
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