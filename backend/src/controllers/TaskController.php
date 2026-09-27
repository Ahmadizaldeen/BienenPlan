<?php

namespace BienenPlan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use BienenPlan\Models\Task;
use BienenPlan\Services\FileValidationService;

class TaskController {
    private Task $taskModel;
    private FileValidationService $fileValidationService;

    public function __construct(
        Task $taskModel,
        ?FileValidationService $fileValidationService = null,
    ) {
        $this->taskModel = $taskModel; # Dependency Injection, Task in TaskController verfügbar machen
        $this->fileValidationService = $fileValidationService ?? new FileValidationService();
    }

    // Helper für JSON-Antworten
    private function jsonResponse(Response $response, array $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    // GET /api/tasks
    public function getAllByUser(Request $request, Response $response): Response {
        $userId = (int) $request->getAttribute('user_id');
        $tasks = $this->taskModel->getAllByUser($userId);
        return $this->jsonResponse($response, $tasks);
    }

    // GET /api/tasks/{id}
    public function getById(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $task = $this->taskModel->getById($id);

        if (!$task) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, $task);
    }

    // POST /api/tasks
    public function create(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        $userId = $request->getAttribute('user_id');

        if (empty($data['title']) || empty($data['container_id'])) {
            return $this->jsonResponse($response, ['error' => 'title und container_id sind erforderlich'], 400);
        }

        $data['created_by'] = $userId;

        try {
            $taskId = $this->taskModel->create($data);
            return $this->jsonResponse($response, [
                'message' => 'Task erfolgreich erstellt',
                'id' => $taskId
            ], 201);
        } catch (\PDOException $e) {
    return $this->jsonResponse($response, [
        'error' => 'Container oder User existiert nicht',
        'debug_message' => $e->getMessage()
    ], 400);
}
    }

    // PUT /api/tasks/{id}
    public function update(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        $existingTask = $this->taskModel->getById($id);
        if (!$existingTask) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
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
        $userId = $request->getAttribute('user_id');

        $existingTask = $this->taskModel->getById($id);
        if (!$existingTask) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }

        $this->taskModel->delete($id, $userId);
        return $this->jsonResponse($response, ['message' => 'Task erfolgreich gelöscht']);
    }

    // POST /api/tasks/{id}/status
    public function updateStatus(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();
        $status = is_array($data) ? ($data['status'] ?? null) : null;

        if (empty($status)) {
            return $this->jsonResponse($response, ['error' => 'status ist erforderlich'], 400);
        }

        $existingTask = $this->taskModel->getById($id);
        if (!$existingTask) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }

        $this->taskModel->updateStatus($id, $status);
        return $this->jsonResponse($response, ['message' => 'Status erfolgreich aktualisiert']);
    }

    // POST /api/tasks/{id}/attachment (multipart/form-data, Feld: "file")
    public function uploadAttachment(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];

        $existingTask = $this->taskModel->getById($id);
        if (!$existingTask) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if (!$file) {
            return $this->jsonResponse($response, ['error' => 'Keine gültige Datei hochgeladen'], 400);
        }

        try {
            $validation = $this->fileValidationService->validate(
                $file,
                [
                    'pdf' => ['application/pdf'],
                    'png' => ['image/png'],
                    'jpg' => ['image/jpeg'],
                    'jpeg' => ['image/jpeg'],
                    'gif' => ['image/gif'],
                    'docx' => [
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/zip',
                    ],
                    'xlsx' => [
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/zip',
                    ],
                    'txt' => ['text/plain'],
                ],
                10 * 1024 * 1024,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonResponse($response, ['error' => $exception->getMessage()], 400);
        }

        $extension = $validation['extension'];

        $uploadDir = __DIR__ . '/../../public/uploads/tasks';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeName = sprintf('%d_%s.%s', $id, bin2hex(random_bytes(8)), $extension);
        $file->moveTo($uploadDir . '/' . $safeName);

        $relativePath = 'uploads/tasks/' . $safeName;
        $this->taskModel->updateAttachment($id, $relativePath);

        return $this->jsonResponse($response, [
            'message' => 'Anhang erfolgreich hochgeladen',
            'attachment' => $relativePath,
        ]);
    }
}