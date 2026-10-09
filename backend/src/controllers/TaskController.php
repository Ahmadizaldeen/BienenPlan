<?php

namespace BienenPlan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use BienenPlan\Models\Task;

class TaskController {
    private Task $taskModel;

    public function __construct(
        Task $taskModel,
        private TaskAttachmentController $attachmentController,
    ) {
        $this->taskModel = $taskModel; # Dependency Injection, Task in TaskController verfügbar machen
    }

    // Helper für JSON-Antworten
    private function jsonResponse(Response $response, array $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    public function move(Request $request, Response $response, array $args): Response {
        $data = $request->getParsedBody();
        $target = is_array($data) ? filter_var($data['container_id'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]) : false;
        if ($target === false) {
            return $this->jsonResponse($response, ['error' => 'Gueltige container_id erforderlich'], 400);
        }
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        if (!$this->taskModel->isVisibleToUser($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }
        try {
            $this->taskModel->move($id, $target, $userId);
        } catch (\DomainException $exception) {
            return $this->jsonResponse($response, ['error' => $exception->getMessage()], 403);
        }
        return $this->jsonResponse($response, ['message' => 'Task verschoben']);
    }

    // GET /api/tasks
    public function getAllByUser(Request $request, Response $response): Response {
        $userId = (int) $request->getAttribute('user_id');
        $query = $request->getQueryParams();
        $projectId = isset($query['project_id']) ? filter_var($query['project_id'], FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]) : null;
        if ($projectId === false) {
            return $this->jsonResponse($response, ['error' => 'Ungueltige project_id'], 400);
        }
        $tasks = $this->taskModel->getAllByUser($userId, $projectId);
        return $this->jsonResponse($response, $tasks);
    }

    // GET /api/tasks/{id}
    public function getById(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        if (!$this->taskModel->isVisibleToUser($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }
        $task = $this->taskModel->getById($id, $userId);

        if (!$task) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, $task);
    }

    // POST /api/tasks
    public function create(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        $userId = (int) $request->getAttribute('user_id');

        if (!is_array($data) || empty($data['title']) || empty($data['container_id'])) {
            return $this->jsonResponse($response, ['error' => 'title und container_id sind erforderlich'], 400);
        }

        if (!$this->taskModel->canAccessContainer((int) $data['container_id'], (int) $userId)) {
            return $this->jsonResponse($response, ['error' => 'Container nicht gefunden'], 404);
        }

        $data['created_by'] = $userId;

        try {
            $taskId = $this->taskModel->create($data);
            return $this->jsonResponse($response, [
                'message' => 'Task erfolgreich erstellt',
                'id' => $taskId
            ], 201);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return $this->jsonResponse($response, [
                'error' => 'Container oder User existiert nicht',
            ], 400);
        }
    }

    // PUT /api/tasks/{id}
    public function update(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        $data = $request->getParsedBody();

        if (!$this->taskModel->isVisibleToUser($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }
        if (!$this->taskModel->canEdit($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Keine Berechtigung zum Bearbeiten'], 403);
        }
        if (!is_array($data) || array_diff(array_keys($data), ['title', 'description', 'status', 'deadline'])) {
            return $this->jsonResponse($response, ['error' => 'Nur title, description, status und deadline sind erlaubt'], 400);
        }
        if (isset($data['status']) && !in_array($data['status'], ['open', 'in_progress', 'done', 'timed_out'], true)) {
            return $this->jsonResponse($response, ['error' => 'Ungueltiger Status'], 400);
        }

        if (empty($data['title'])) {
            return $this->jsonResponse($response, ['error' => 'title darf nicht leer sein'], 400);
        }

        $this->taskModel->update($id, $data);
        return $this->jsonResponse($response, ['message' => 'Task erfolgreich aktualisiert']);
    }

    // DELETE /api/tasks/{id}
    public function delete(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');

        if ($id < 1) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }

        // Do not require visibility first: container ownership can grant deletion alone.
        $canDelete = $this->taskModel->canDelete($id, $userId);
        $isVisible = $this->taskModel->isVisibleToUser($id, $userId);
        if (!$canDelete && !$isVisible) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }

        if (!$canDelete) {
            return $this->jsonResponse($response, [
                'error' => 'Du darfst diese Task nicht löschen. Das dürfen nur der Ersteller, der Container-Inhaber oder der Projekt-Owner.',
            ], 403);
        }

        if (!$this->taskModel->delete($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, ['message' => 'Task erfolgreich gelöscht']);
    }

    // POST /api/tasks/{id}/status
    public function updateStatus(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        $data = $request->getParsedBody();
        $status = is_array($data) ? ($data['status'] ?? null) : null;

        if (!is_string($status) || !in_array($status, ['open', 'in_progress', 'done', 'timed_out'], true)) {
            return $this->jsonResponse($response, ['error' => 'status ist erforderlich'], 400);
        }

        if (!$this->taskModel->isVisibleToUser($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }

        if (!$this->taskModel->canChangeStatus($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Archivierte Projekte sind schreibgeschuetzt'], 403);
        }
        $this->taskModel->updateStatus($id, $status);
        return $this->jsonResponse($response, ['message' => 'Status erfolgreich aktualisiert']);
    }

    // POST /api/tasks/{id}/attachment (multipart/form-data, Feld: "file")
    public function uploadAttachment(Request $request, Response $response, array $args): Response {
        return $this->attachmentController->uploadLegacy($request, $response, $args);
    }
}