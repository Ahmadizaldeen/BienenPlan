<?php

namespace BienenPlan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use BienenPlan\Models\Container;

class ContainerController {

    private Container $containerModel;

    public function __construct(Container $containerModel) {
        $this->containerModel = $containerModel;
    }

    // Helper für JSON-Antworten
    private function jsonResponse(Response $response, array $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    // GET /api/containers
    public function getAll(Request $request, Response $response): Response {
        $userId = (int) $request->getAttribute('user_id');
        $containers = $this->containerModel->getAll($userId);
        return $this->jsonResponse($response, $containers);
    }

    // GET /api/containers/{id}
    public function getOne(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        $container = $this->containerModel->findById($id);

        // Fremde Container wie "nicht gefunden" behandeln, um ihre Existenz nicht preiszugeben.
        if (!$container || !$this->containerModel->isVisibleToUser($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Container nicht gefunden.'], 404);
        }

        return $this->jsonResponse($response, $container);
    }

    // POST /api/containers
    public function create(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        $userId = $request->getAttribute('user_id');

        if (!is_array($data) || empty(trim((string) ($data['title'] ?? '')))) {
            return $this->jsonResponse($response, ['error' => 'Titel ist erforderlich.'], 400);
        }

        $title = trim((string) $data['title']);
        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;

        if (empty($projectId)) {
            return $this->jsonResponse($response, ['error' => 'project_id ist erforderlich.'], 400);
        }

        try {
            $id = $this->containerModel->create([
                'title' => $title,
                'project_id' => $projectId,
                'created_by' => $userId,
            ]);

            return $this->jsonResponse($response, [
                'message' => 'Container erfolgreich erstellt',
                'id' => $id,
                'data' => [
                    'id' => $id,
                    'title' => $title,
                    'project_id' => $projectId,
                ],
            ], 201);
        } catch (\PDOException $e) {
            return $this->jsonResponse($response, [
                'error' => 'DB Fehler beim Erstellen des Containers',
                'debug_message' => $e->getMessage()
            ], 400);
        }
    }

    // PUT /api/containers/{id}
    public function update(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        $data = $request->getParsedBody();

        $container = $this->containerModel->findById($id);
        if (!$container || !$this->containerModel->isVisibleToUser($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Container nicht gefunden.'], 404);
        }

        $title = trim((string) ($data['title'] ?? ''));

        if (empty($title)) {
            return $this->jsonResponse($response, ['error' => 'Titel ist erforderlich.'], 400);
        }

        try {
            $this->containerModel->update($id, $title);
            return $this->jsonResponse($response, ['message' => 'Container aktualisiert.']);
        } catch (\PDOException $e) {
            return $this->jsonResponse($response, [
                'error' => 'DB Fehler',
                'debug_message' => $e->getMessage()
            ], 400);
        }
    }

    // DELETE /api/containers/{id}
    public function delete(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');

        $container = $this->containerModel->findById($id);
        if (!$container || !$this->containerModel->isVisibleToUser($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Container nicht gefunden.'], 404);
        }

        try {
            $this->containerModel->delete($id);
            return $this->jsonResponse($response, ['message' => 'Container gelöscht.']);
        } catch (\PDOException $e) {
            return $this->jsonResponse($response, [
                'error' => 'DB Fehler beim Löschen',
                'debug_message' => $e->getMessage()
            ], 400);
        }
    }
}