<?php

namespace BienenPlan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use BienenPlan\Models\Project;

class ProjectController {
    private Project $projectModel;

    public function __construct(Project $projectModel) {
        $this->projectModel = $projectModel;
    }

    // Helper für JSON-Antworten
    private function jsonResponse(Response $response, array $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }


        // POST /api/project
    public function create(Request $request, Response $response): Response {
            $data = $request->getParsedBody();
        $userId = $request->getAttribute('user_id');

            if (!is_array($data) || empty(trim((string) ($data['name'] ?? '')))) {
                return $this->jsonResponse($response, ['error' => 'name ist erforderlich'], 400);
            }

        $data['created_by'] = $userId;

        try {
            $projectId = $this->projectModel->create($data);
            return $this->jsonResponse($response, [
                'message' => 'Projekt erfolgreich erstellt',
                'id' => $projectId
            ], 201);
        } catch (\PDOException $e) {
    return $this->jsonResponse($response, [
        'error' => $e->getMessage(),
        'debug_message' => $e->getMessage()
    ], 400);
}
    }

    // GET /api/projects
    public function getAll(Request $request, Response $response): Response {
        
        $userId = $request->getAttribute('user_id');
        $projekts = $this->projectModel->getAll($userId);
        return $this->jsonResponse($response, $projekts);
    }

    // GET /api/projects/{id}
    public function getById(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $project = $this->projectModel->getById($id);

        if (!$project) {
            return $this->jsonResponse($response, ['error' => 'Projekt nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, $project);
    }

        // DELETE /api/projects/{id}
    public function delete(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = $request->getAttribute('user_id');
        

        $this->projectModel->archive($id, $userId);
        return $this->jsonResponse($response, ['message' => 'Projekt erfolgreich archiviert']);
    }

    // PUT /api/projects/{id}
    public function update(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        $existingProject = $this->projectModel->getById($id);
        if (!$existingProject) {
            return $this->jsonResponse($response, ['error' => 'Projekt nicht gefunden'], 404);
        }

        if (empty($data['name'])) {
            return $this->jsonResponse($response, ['error' => 'name darf nicht leer sein'], 400);
        }

        $this->projectModel->update($id, $data);
        return $this->jsonResponse($response, ['message' => 'Projekt erfolgreich aktualisiert']);
    }


}