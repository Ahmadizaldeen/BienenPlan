<?php

namespace BienenPlan\Controllers;

use BienenPlan\Models\Subtask;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SubtaskController {
    public function __construct(private Subtask $subtasks) {}

    private function json(Response $response, array $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function canCreate(array $task, int $userId): bool {
        return $this->subtasks->access()->canCreateSubtask($task, $userId);
    }

    private function item(array $item, array $task, int $userId): array {
        $canEdit = $this->subtasks->access()->canEditSubtask($task, $item, $userId);
        $canDelete = $this->subtasks->access()->canDeleteSubtask($task, $item, $userId);
        return [
            'id' => (int) $item['id'], 'task_id' => (int) $item['task_id'],
            'title' => $item['title'], 'completed' => (bool) $item['completed'],
            'created_by' => isset($item['created_by']) ? (int) $item['created_by'] : null,
            'can_edit' => $canEdit, 'can_delete' => $canDelete, 'can_complete' => (bool) $task['can_write'],
        ];
    }

    private function validTitle(mixed $title): bool {
        return is_string($title) && trim($title) !== ''
            && preg_match('//u', $title) === 1
            && preg_match_all('/./us', trim($title)) <= 100;
    }

    public function index(Request $request, Response $response, array $args): Response {
        $userId = (int) $request->getAttribute('user_id');
        $task = $this->subtasks->accessibleTask((int) $args['taskId'], $userId);
        if (!$task) return $this->json($response, ['error' => 'Aufgabe nicht gefunden'], 404);
        return $this->json($response, [
            'can_create' => $this->canCreate($task, $userId),
            'subtasks' => array_map(fn(array $item): array => $this->item($item, $task, $userId), $this->subtasks->byTask((int) $args['taskId'])),
        ]);
    }

    public function create(Request $request, Response $response, array $args): Response {
        $userId = (int) $request->getAttribute('user_id');
        $taskId = (int) $args['taskId'];
        $task = $this->subtasks->accessibleTask($taskId, $userId);
        if (!$task) return $this->json($response, ['error' => 'Aufgabe nicht gefunden'], 404);
        if (!$this->canCreate($task, $userId)) return $this->json($response, ['error' => 'Keine Berechtigung zum Erstellen'], 403);
        $data = $request->getParsedBody();
        if (!is_array($data) || !$this->validTitle($data['title'] ?? null)) {
            return $this->json($response, ['error' => 'Titel muss 1 bis 100 Zeichen enthalten'], 400);
        }
        try {
            $id = $this->subtasks->create($taskId, $userId, trim($data['title']));
        } catch (\DomainException $exception) {
            return $this->json($response, ['error' => $exception->getMessage()], 409);
        }
        return $this->json($response, $this->item($this->subtasks->byId($taskId, $id), $task, $userId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response {
        $userId = (int) $request->getAttribute('user_id');
        $taskId = (int) $args['taskId'];
        $id = (int) $args['subtaskId'];
        $task = $this->subtasks->accessibleTask($taskId, $userId);
        if (!$task) return $this->json($response, ['error' => 'Aufgabe nicht gefunden'], 404);
        $item = $this->subtasks->byId($taskId, $id);
        if (!$item) return $this->json($response, ['error' => 'Teilaufgabe nicht gefunden'], 404);
        if (!$task['can_write']) return $this->json($response, ['error' => 'Archivierte Projekte sind schreibgeschuetzt'], 403);
        $data = $request->getParsedBody();
        if (!is_array($data) || $data === [] || array_diff(array_keys($data), ['title', 'completed'])) {
            return $this->json($response, ['error' => 'Nur title und completed sind erlaubt'], 400);
        }
        // Enforce title permissions even when a request also contains a checkbox change.
        if (array_key_exists('title', $data)) {
            if (!$this->item($item, $task, $userId)['can_edit']) return $this->json($response, ['error' => 'Keine Berechtigung zum Bearbeiten'], 403);
            if (!$this->validTitle($data['title'])) return $this->json($response, ['error' => 'Titel muss 1 bis 100 Zeichen enthalten'], 400);
            $data['title'] = trim($data['title']);
        }
        if (array_key_exists('completed', $data) && !is_bool($data['completed'])) {
            return $this->json($response, ['error' => 'completed muss ein Boolean sein'], 400);
        }
        if (!$this->subtasks->update($taskId, $id, $data, $userId)) {
            return $this->json($response, ['error' => 'Teilaufgabe oder Berechtigung inzwischen geaendert'], 409);
        }
        return $this->json($response, $this->item($this->subtasks->byId($taskId, $id), $task, $userId));
    }

    public function delete(Request $request, Response $response, array $args): Response {
        $userId = (int) $request->getAttribute('user_id');
        $taskId = (int) $args['taskId'];
        $id = (int) $args['subtaskId'];
        $task = $this->subtasks->accessibleTask($taskId, $userId);
        if (!$task) return $this->json($response, ['error' => 'Aufgabe nicht gefunden'], 404);
        $item = $this->subtasks->byId($taskId, $id);
        if (!$item) return $this->json($response, ['error' => 'Teilaufgabe nicht gefunden'], 404);
        if (!$this->item($item, $task, $userId)['can_delete']) return $this->json($response, ['error' => 'Keine Berechtigung zum Loeschen'], 403);
        if (!$this->subtasks->delete($taskId, $id, $userId)) {
            return $this->json($response, ['error' => 'Teilaufgabe oder Berechtigung inzwischen geaendert'], 409);
        }
        return $this->json($response, ['message' => 'Teilaufgabe geloescht']);
    }
}