<?php

namespace BienenPlan\Controllers;

use BienenPlan\Models\Container;
use BienenPlan\Models\Group;
use BienenPlan\Models\Project;
use BienenPlan\Models\Subtask;
use BienenPlan\Models\Task;
use BienenPlan\Services\OllamaServiceException;
use BienenPlan\Services\OllamaService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ChatController
{
    public function __construct(
        private readonly OllamaService $ollama,
        private readonly Project $projects,
        private readonly Task $tasks,
        private readonly Container $containers,
        private readonly Group $groups,
        private readonly Subtask $subtasks,
    ) {
    }

    public function chat(Request $request, Response $response, array $args): Response
    {
        $projectId = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]); // Validate project ID
        $userId = (int) $request->getAttribute('user_id'); 
        $project = $projectId === false ? null : $this->projects->getById($projectId, $userId);

        if ($project === null) {
            return $this->errorResponse(
                $response,
                'project_not_found',
                'Das Projekt wurde nicht gefunden oder Sie haben keinen Zugriff darauf.',
                404,
            );
        }

        $body = $request->getParsedBody();
        $messages = $this->validateMessages(is_array($body) ? ($body['messages'] ?? null) : null);
        if ($messages === null) {
            return $this->errorResponse(
                $response,
                'invalid_chat_messages',
                'Der Nachrichtenverlauf ist ungültig. Senden Sie 1 bis 20 nicht leere Nachrichten mit den Rollen „user“ oder „assistant“; die letzte Nachricht muss von „user“ stammen. Pro Nachricht sind höchstens 16.000 Zeichen und insgesamt höchstens 64.000 Zeichen erlaubt.',
                400,
            );
        }

        try {
            $projectContext = $this->buildProjectContext(
                $project,
                $userId,
                (bool) $request->getAttribute('is_admin'),
            );
        } catch (\JsonException $exception) {
            return $this->errorResponse(
                $response,
                'chat_context_encoding_failed',
                'Die Projektdaten konnten nicht für den Chat aufbereitet werden. Bitte versuchen Sie es erneut.',
                500,
            );
        }
        if (strlen(json_encode($projectContext, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) > 100000) {
            return $this->errorResponse(
                $response,
                'chat_context_too_large',
                'Der sichtbare Projektkontext ist zu umfangreich für eine Chat-Anfrage. Bitte versuchen Sie es mit einer konkreteren Frage oder reduzieren Sie die Projektdaten.',
                413,
            );
        }

        try {
            $content = $this->ollama->chat((string) $project['name'], $projectContext, $messages);
        } catch (OllamaServiceException $exception) {
            return $this->errorResponse(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return $this->jsonResponse($response, [
            'message' => ['role' => 'assistant', 'content' => $content],
        ]);
    }

    private function buildProjectContext(array $project, int $userId, bool $isAdmin): array
    {
        $visibleTasks = $this->tasks->getAllByUser((int) $userId, (int) $project['id']);
        $visibleContainers = $this->containers->getAll($userId, (int) $project['id']);
        $projectGroups = $this->projects->getGroups((int) $project['id'], $userId);
        $membersByGroup = $this->groups->getMemberNamesByGroupIds(
            array_map(static fn(array $group): int => (int) $group['id'], $projectGroups),
        );
        $groups = array_map(static function (array $group) use ($membersByGroup): array {
            $members = $membersByGroup[(int) $group['id']] ?? [];

            return [
                'name' => (string) $group['name'],
                'is_global' => (bool) $group['is_global'],
                'member_names' => $members,
                'member_count' => count($members),
            ];
        }, $projectGroups);
        $subtasksByTask = $this->subtasks->byTasks(
            array_map(static fn(array $task): int => (int) $task['id'], $visibleTasks),
        );

        $taskData = array_map(static function (array $task) use ($subtasksByTask): array {
            $subtasks = $subtasksByTask[(int) $task['id']] ?? [];
            return [
                'title' => (string) $task['title'],
                'description' => (string) ($task['description'] ?? ''),
                'status' => (string) $task['status'],
                'deadline' => $task['deadline'] ?? null,
                'created_at' => $task['created_at'] ?? null,
                'container' => (string) $task['container_title'],
                'created_by' => $task['creator_name'] ?? null,
                'groups' => $task['group_names'] === null || $task['group_names'] === ''
                    ? []
                    : explode(', ', (string) $task['group_names']),
                'subtasks' => array_map(
                    static fn(array $subtask): array => [
                        'title' => (string) $subtask['title'],
                        'completed' => (bool) $subtask['completed'],
                    ],
                    $subtasks,
                ),
            ];
        }, $visibleTasks);

        $statusCounts = [];
        foreach ($taskData as $task) {
            $statusCounts[$task['status']] = ($statusCounts[$task['status']] ?? 0) + 1;
        }

        $allProjectTasksVisible = $isAdmin || (bool) ($project['is_owner'] ?? false);
        return [
            'project' => ['name' => (string) $project['name']],
            'task_visibility_scope' => $allProjectTasksVisible
                ? 'Alle nicht gelöschten Aufgaben des Projekts, die in sichtbaren Containern liegen.'
                : 'Nur Aufgaben, die diesem Benutzer laut Aufgaben- und Gruppenzuweisungen sichtbar sind; keine Gesamtheit aller Projektaufgaben.',
            'containers' => array_map(
                static fn(array $container): string => (string) $container['title'],
                $visibleContainers,
            ),
            'groups' => $groups,
            'visible_tasks' => $taskData,
            'statistics' => [
                'visible_task_count' => count($taskData),
                'task_count_by_status' => $statusCounts,
                'subtask_count' => array_sum(array_map(
                    static fn(array $task): int => count($task['subtasks']),
                    $taskData,
                )),
                'completed_subtask_count' => array_sum(array_map(
                    static fn(array $task): int => count(array_filter(
                        $task['subtasks'],
                        static fn(array $subtask): bool => $subtask['completed'],
                    )),
                    $taskData,
                )),
                'group_count' => count($groups),
                'container_count' => count($visibleContainers),
            ],
        ];
    }

    private function validateMessages(mixed $input): ?array
    {
        if (!is_array($input) || count($input) < 1 || count($input) > 20) {
            return null;
        }

        $messages = [];
        $totalBytes = 0;
        foreach ($input as $message) {
            if (!is_array($message)) {
                return null;
            }

            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;
            if (
                !in_array($role, ['user', 'assistant'], true)
                || !is_string($content)
                || trim($content) === ''
                || strlen($content) > 16000
            ) {
                return null;
            }

            $totalBytes += strlen($content);
            if ($totalBytes > 64000) {
                return null;
            }
            $messages[] = ['role' => $role, 'content' => trim($content)];
        }

        return end($messages)['role'] === 'user' ? $messages : null;
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function errorResponse(Response $response, string $code, string $message, int $status): Response
    {
        // `error` remains a string for existing clients; code/status let clients handle cases explicitly.
        return $this->jsonResponse($response, [
            'error' => $message,
            'code' => $code,
            'status' => $status,
        ], $status);
    }
}
