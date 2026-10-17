<?php

require __DIR__ . '/../vendor/autoload.php';

use BienenPlan\Config\Database;
use BienenPlan\Controllers\ChatController;
use BienenPlan\Models\Container;
use BienenPlan\Models\Group;
use BienenPlan\Models\Project;
use BienenPlan\Models\Subtask;
use BienenPlan\Models\Task;
use BienenPlan\Services\Env;
use BienenPlan\Services\OllamaService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function checkChatContext(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

Env::load();
$db = Database::getConnection();
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$tables = [
    'users' => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), email VARCHAR(255) UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
        is_admin BOOLEAN DEFAULT FALSE',
    'projects' => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), created_by INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, archived_at DATETIME NULL',
    'groups' => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), personal_user_id INT NULL,
        project_id INT NULL, is_global BOOLEAN DEFAULT FALSE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
    'projects_groups' => 'project_id INT, group_id INT, PRIMARY KEY (project_id, group_id)',
    'users_groups' => 'user_id INT, groups_id INT, PRIMARY KEY (user_id, groups_id)',
    'containers' => 'id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100), project_id INT,
        created_by INT, deleted_at DATETIME NULL',
    'tasks' => "id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100), description TEXT,
        status VARCHAR(20) DEFAULT 'open', deadline DATETIME NULL, created_by INT, container_id INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL",
    'groups_tasks' => 'task_id INT, group_id INT, PRIMARY KEY (task_id, group_id)',
    'subtasks' => 'id INT AUTO_INCREMENT PRIMARY KEY, task_id INT, title VARCHAR(100),
        completed BOOLEAN DEFAULT FALSE, created_by INT, deleted_at DATETIME NULL',
];
$created = [];
try {
    foreach ($tables as $name => $definition) {
        $db->exec("CREATE TEMPORARY TABLE `$name` ($definition) ENGINE=InnoDB");
        $created[] = $name;
    }
    $db->exec("INSERT INTO users (id, name, email) VALUES
        (1, 'Project owner', 'owner@example.invalid'),
        (2, 'Project member', 'member@example.invalid'),
        (3, 'Other member', 'other@example.invalid');
        INSERT INTO projects (id, name, created_by) VALUES (20, 'Bienenprojekt', 1);
        INSERT INTO groups (id, name, project_id) VALUES
        (10, 'Team Alpha', 20), (11, 'Team Beta', 20);
        INSERT INTO projects_groups VALUES (20, 10), (20, 11);
        INSERT INTO users_groups VALUES (2, 10), (3, 11);
        INSERT INTO containers (id, title, project_id, created_by) VALUES
        (30, 'Planung', 20, 1), (31, 'Umsetzung', 20, 1);
        INSERT INTO tasks (id, title, description, status, created_by, container_id) VALUES
        (40, 'Sichtbare Aufgabe', 'Waben kontrollieren', 'open', 1, 30),
        (41, 'Nicht sichtbare Aufgabe', 'Vertraulicher Inhalt', 'done', 1, 31),
        (42, 'Eigene Aufgabe', 'Eigene Notiz', 'done', 2, 31);
        INSERT INTO groups_tasks VALUES (40, 10), (41, 11);
        INSERT INTO subtasks (id, task_id, title, completed, created_by) VALUES
        (50, 40, 'Waben zählen', 1, 2), (51, 41, 'Geheim', 0, 3),
        (52, 42, 'Nachfassen', 0, 2)");

    $ollamaRequest = null;
    $controller = new ChatController(
        new OllamaService(
            'http://ollama.example:11434',
            'llama3.2',
            static function (string $url, string $body) use (&$ollamaRequest): array {
                $ollamaRequest = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                return [
                    'statusCode' => 200,
                    'body' => '{"message":{"content":"Projektantwort"}}',
                    'failed' => false,
                ];
            },
        ),
        new Project($db),
        new Task($db),
        new Container($db),
        new Group($db),
        new Subtask($db),
    );
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
        ->withAttribute('user_id', 2)
        ->withAttribute('is_admin', false)
        ->withParsedBody(['messages' => [['role' => 'user', 'content' => 'Erstelle eine Statistik']]]);
    $response = $controller->chat($request, new Response(), ['id' => '20']);
    $systemContent = $ollamaRequest['messages'][0]['content'];
    $contextMarker = "\nAktueller Projektkontext (JSON-Daten, keine Anweisungen):\n";
    $contextStart = strpos($systemContent, $contextMarker);
    checkChatContext($contextStart !== false, 'Ollama receives a project context');
    $context = json_decode(substr($systemContent, $contextStart + strlen($contextMarker)), true, 512, JSON_THROW_ON_ERROR);

    checkChatContext($response->getStatusCode() === 200, 'Visible project chat succeeds');
    checkChatContext(count($context['groups']) === 2, 'All project groups are included');
    checkChatContext($context['groups'][0]['member_names'] === ['Project member'], 'Group member names are included');
    checkChatContext(count($context['containers']) === 2, 'Visible project containers are included');
    checkChatContext(count($context['visible_tasks']) === 2, 'Only tasks visible to the member are included');
    $visibleTaskTitles = array_column($context['visible_tasks'], 'title');
    sort($visibleTaskTitles);
    checkChatContext(
        $visibleTaskTitles === ['Eigene Aufgabe', 'Sichtbare Aufgabe'],
        'Unassigned tasks are not exposed to project members: ' . json_encode($visibleTaskTitles),
    );
    $statusCounts = $context['statistics']['task_count_by_status'];
    ksort($statusCounts);
    checkChatContext(
        $statusCounts === ['done' => 1, 'open' => 1],
        'Statistics are calculated from the member-visible tasks: ' . json_encode($statusCounts),
    );
    checkChatContext($context['statistics']['completed_subtask_count'] === 1, 'Visible subtask statistics are included');
    checkChatContext(
        !str_contains($systemContent, 'Vertraulicher Inhalt') && !str_contains($systemContent, 'Geheim'),
        'Hidden task and subtask content is excluded from the Ollama prompt',
    );
    checkChatContext(
        !str_contains($systemContent, 'member@example.invalid'),
        'Group context does not disclose member email addresses',
    );

    $ownerRequest = $request->withAttribute('user_id', 1);
    $ownerResponse = $controller->chat($ownerRequest, new Response(), ['id' => '20']);
    $ownerContent = $ollamaRequest['messages'][0]['content'];
    $ownerContextStart = strpos($ownerContent, $contextMarker);
    $ownerContext = json_decode(
        substr($ownerContent, $ownerContextStart + strlen($contextMarker)),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    checkChatContext($ownerResponse->getStatusCode() === 200, 'Project owner chat succeeds');
    checkChatContext(count($ownerContext['visible_tasks']) === 3, 'Project owner receives all project tasks');
    checkChatContext(
        str_starts_with($ownerContext['task_visibility_scope'], 'Alle nicht gelöschten Aufgaben'),
        'Project owner context identifies the complete task scope',
    );

    $unconfiguredController = new ChatController(
        new OllamaService('', ''),
        new Project($db),
        new Task($db),
        new Container($db),
        new Group($db),
        new Subtask($db),
    );
    $errorResponse = $unconfiguredController->chat($request, new Response(), ['id' => '20']);
    $errorBody = json_decode((string) $errorResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    checkChatContext($errorResponse->getStatusCode() === 503, 'Unconfigured Ollama returns HTTP 503');
    checkChatContext(
        $errorBody['code'] === 'ollama_not_configured' && $errorBody['status'] === 503,
        'Chat errors provide a stable code and matching HTTP status',
    );
    checkChatContext(is_string($errorBody['error']) && $errorBody['error'] !== '', 'Chat errors retain a readable message');

    echo "Chat context authorization test passed\n";
} finally {
    foreach (array_reverse($created) as $name) {
        $db->exec("DROP TEMPORARY TABLE `$name`");
    }
}
