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
        $query = $request->getQueryParams();
        $projectId = isset($query['project_id']) ? filter_var($query['project_id'], FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]) : null;
        if ($projectId === false) {
            return $this->jsonResponse($response, ['error' => 'Ungueltige project_id'], 400);
        }
        $containers = $this->containerModel->getAll($userId, $projectId);
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
        if (!$this->containerModel->canCreateInProject($projectId, (int) $userId)) {
            return $this->jsonResponse($response, ['error' => 'Projekt nicht gefunden'], 404);
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
        } catch (\DomainException $exception) {
            return $this->jsonResponse($response, ['error' => $exception->getMessage()], 404);
        } catch (\PDOException $e) {
            throw $e;
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
        if (!$this->containerModel->canManage($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Keine Berechtigung'], 403);
        }

        $title = trim((string) ($data['title'] ?? ''));

        if (empty($title)) {
            return $this->jsonResponse($response, ['error' => 'Titel ist erforderlich.'], 400);
        }

        try {
            if (!$this->containerModel->update($id, $title, $userId)) {
                return $this->jsonResponse($response, ['error' => 'Container oder Berechtigung inzwischen geaendert'], 409);
            }
            return $this->jsonResponse($response, ['message' => 'Container aktualisiert.']);
        } catch (\PDOException $e) {
            throw $e;
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
        if (!$this->containerModel->canManage($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Keine Berechtigung'], 403);
        }

        try {
            if (!$this->containerModel->delete($id, $userId)) {
                return $this->jsonResponse($response, ['error' => 'Container nicht gefunden'], 404);
            }
            return $this->jsonResponse($response, ['message' => 'Container gelöscht.']);
        } catch (\DomainException $exception) {
            return $this->jsonResponse($response, ['error' => $exception->getMessage()], 409);
        } catch (\PDOException $e) {
            throw $e;
        }
    }
}