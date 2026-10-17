<?php

require __DIR__ . '/../vendor/autoload.php';

use BienenPlan\Services\OllamaService;
use BienenPlan\Services\OllamaServiceException;

$service = new OllamaService('', '');
try {
    $service->chat('Testprojekt', [], [['role' => 'user', 'content' => 'Hallo']]);
    throw new RuntimeException('Unconfigured Ollama service should fail');
} catch (RuntimeException $exception) {
    if (
        !($exception instanceof OllamaServiceException)
        || $exception->errorCode !== 'ollama_not_configured'
        || $exception->httpStatus !== 503
    ) {
        throw $exception;
    }
}

/** @var array{url: string, body: array<string, mixed>}|null $request */
$request = null;
$service = new OllamaService(
    'http://ollama.example:11434/',
    'llama3.2',
    static function (string $url, string $body) use (&$request): array {
        $request = ['url' => $url, 'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
        return [
            'statusCode' => 200,
            'body' => '{"message":{"content":"  Antwort  "}}',
            'failed' => false,
        ];
    },
);
$context = [
    'groups' => [['name' => 'Team Bienen', 'member_names' => ['Ada']]],
    'visible_tasks' => [['title' => 'Waben prüfen', 'status' => 'open']],
    'statistics' => ['visible_task_count' => 1],
];
$answer = $service->chat('Testprojekt', $context, [['role' => 'user', 'content' => 'Hallo']]);
if ($answer !== 'Antwort') {
    throw new RuntimeException('Ollama response content should be trimmed');
}
if ($request === null) {
    throw new RuntimeException('Ollama transport should receive the request');
}
if ($request['url'] !== 'http://ollama.example:11434/api/chat') {
    throw new RuntimeException('Ollama service should call the chat endpoint');
}
if (
    $request['body']['model'] !== 'llama3.2'
    || $request['body']['stream'] !== false
    || $request['body']['messages'][0]['role'] !== 'system'
    || !str_contains($request['body']['messages'][0]['content'], '"Team Bienen"')
    || !str_contains($request['body']['messages'][0]['content'], '"visible_task_count":1')
    || $request['body']['messages'][1] !== ['role' => 'user', 'content' => 'Hallo']
) {
    throw new RuntimeException('Ollama request should include model and expected messages');
}

$rejectedService = new OllamaService(
    'http://ollama.example:11434',
    'missing-model',
    static fn(string $url, string $body): array => [
        'statusCode' => 404,
        'body' => '{"error":"model not found"}',
        'failed' => false,
    ],
);
try {
    $rejectedService->chat('Testprojekt', [], [['role' => 'user', 'content' => 'Hallo']]);
    throw new RuntimeException('Rejected Ollama request should fail');
} catch (OllamaServiceException $exception) {
    if (
        $exception->errorCode !== 'ollama_request_rejected'
        || $exception->httpStatus !== 502
        || !str_contains($exception->getMessage(), 'HTTP 404')
    ) {
        throw $exception;
    }
}

$failureCases = [
    [
        'upstream' => ['statusCode' => 0, 'body' => '', 'failed' => true],
        'code' => 'ollama_unavailable',
        'status' => 503,
    ],
    [
        'upstream' => ['statusCode' => 200, 'body' => 'not-json', 'failed' => false],
        'code' => 'ollama_invalid_response',
        'status' => 502,
    ],
    [
        'upstream' => ['statusCode' => 200, 'body' => '{"message":{"content":" "}}', 'failed' => false],
        'code' => 'ollama_empty_response',
        'status' => 502,
    ],
];
foreach ($failureCases as $failureCase) {
    $failingService = new OllamaService(
        'http://ollama.example:11434',
        'llama3.2',
        static fn(string $url, string $body): array => $failureCase['upstream'],
    );
    try {
        $failingService->chat('Testprojekt', [], [['role' => 'user', 'content' => 'Hallo']]);
        throw new RuntimeException('Invalid Ollama response should fail');
    } catch (OllamaServiceException $exception) {
        if (
            $exception->errorCode !== $failureCase['code']
            || $exception->httpStatus !== $failureCase['status']
        ) {
            throw $exception;
        }
    }
}

echo "Ollama service configuration test passed\n";
