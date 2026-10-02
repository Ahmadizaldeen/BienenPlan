<?php

namespace BienenPlan\Controllers;

use BienenPlan\Models\TaskAttachment;
use BienenPlan\Services\FileValidationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

class TaskAttachmentController {
    private const TYPES = [
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
    ];

    public function __construct(
        private TaskAttachment $attachments,
        private FileValidationService $validation = new FileValidationService(),
    ) {}

    private function json(Response $response, array $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function task(Request $request, array $args): ?array {
        return $this->attachments->accessibleTask((int) $args['id'], (int) $request->getAttribute('user_id'));
    }

    public function index(Request $request, Response $response, array $args): Response {
        $task = $this->task($request, $args);
        if (!$task) {
            return $this->json($response, ['error' => 'Task nicht gefunden'], 404);
        }
        return $this->json($response, $this->attachments->byTask(
            (int) $args['id'], (int) $request->getAttribute('user_id'), (int) $task['project_owner']
        ));
    }

    public function uploadLegacy(Request $request, Response $response, array $args): Response {
        return $this->upload($request, $response, $args, true);
    }

    public function upload(Request $request, Response $response, array $args, bool $legacyResponse = false): Response {
        if (!$this->task($request, $args)) {
            return $this->json($response, ['error' => 'Task nicht gefunden'], 404);
        }
        $uploaded = $request->getUploadedFiles();
        $files = $uploaded['files'] ?? $uploaded['file'] ?? null;
        if ($files instanceof UploadedFileInterface) {
            $files = [$files];
        }
        if (!is_array($files) || $files === []) {
            return $this->json($response, ['error' => 'Keine Dateien hochgeladen (PHP-Upload-Limits prüfen)'], 400);
        }

        $validated = [];
        try {
            foreach ($files as $file) {
                if (!$file instanceof UploadedFileInterface) {
                    throw new \InvalidArgumentException('Ungültige Dateiliste');
                }
                $result = $this->validation->validate($file, self::TYPES, 10 * 1024 * 1024);
                $name = basename(str_replace('\\', '/', $file->getClientFilename() ?? ''));
                $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
                if ($name === '' || strlen($name) > 255 || preg_match('//u', $name) !== 1) {
                    throw new \InvalidArgumentException('Dateiname fehlt oder ist zu lang');
                }
                $validated[] = [$file, $result, $name];
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        $directory = __DIR__ . '/../../storage/task_attachments';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden');
        }
        $paths = [];
        $ids = [];
        $this->attachments->begin();
        try {
            foreach ($validated as [$file, $result, $name]) {
                $stored = bin2hex(random_bytes(16)) . '.' . $result['extension'];
                $path = $directory . '/' . $stored;
                $file->moveTo($path);
                $paths[] = $path;
                $ids[] = $this->attachments->add(
                    (int) $args['id'], (int) $request->getAttribute('user_id'), $name,
                    $stored, $result['mimeType'], filesize($path)
                );
            }
            $this->attachments->commit();
        } catch (\Throwable $e) {
            $this->attachments->rollback();
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            throw $e;
        }
        $task = $this->task($request, $args);
        $items = array_values(array_filter(
            $this->attachments->byTask(
                (int) $args['id'], (int) $request->getAttribute('user_id'), (int) $task['project_owner']
            ),
            static fn(array $item): bool => in_array((int) $item['id'], $ids, true)
        ));
        if ($legacyResponse) {
            $attachment = $items[0] ?? null;
            return $this->json($response, [
                'message' => 'Anhang erfolgreich hochgeladen',
                'attachment' => sprintf(
                    'api/tasks/%d/attachments/%d/download',
                    (int) $args['id'],
                    (int) $attachment['id']
                ),
            ], 201);
        }
        return $this->json($response, ['attachments' => $items], 201);
    }

    public function download(Request $request, Response $response, array $args): Response {
        if (!$this->task($request, $args)) {
            return $this->json($response, ['error' => 'Task nicht gefunden'], 404);
        }
        $attachment = $this->attachments->byId((int) $args['id'], (int) $args['attachmentId']);
        if (!$attachment) {
            return $this->json($response, ['error' => 'Anhang nicht gefunden'], 404);
        }
        $path = $this->path($attachment['stored_name']);
        if (!is_file($path)) {
            throw new \RuntimeException('Anhang fehlt auf dem Datenträger');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Anhang konnte nicht gelesen werden');
        }
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    throw new \RuntimeException('Anhang konnte nicht vollständig gelesen werden');
                }
                $response->getBody()->write($chunk);
            }
        } finally {
            fclose($handle);
        }
        $name = $attachment['original_name'];
        $asciiName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        return $response
            ->withHeader('Content-Type', $attachment['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name))
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function delete(Request $request, Response $response, array $args): Response {
        $task = $this->task($request, $args);
        if (!$task) {
            return $this->json($response, ['error' => 'Task nicht gefunden'], 404);
        }
        $attachment = $this->attachments->byId((int) $args['id'], (int) $args['attachmentId']);
        if (!$attachment) {
            return $this->json($response, ['error' => 'Anhang nicht gefunden'], 404);
        }
        $userId = (int) $request->getAttribute('user_id');
        if ($userId !== (int) $attachment['uploaded_by'] && $userId !== (int) $task['project_owner']) {
            return $this->json($response, ['error' => 'Keine Berechtigung zum Löschen'], 403);
        }
        $this->attachments->remove((int) $args['id'], (int) $args['attachmentId']);
        $path = $this->path($attachment['stored_name']);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Anhang konnte nicht vom Datenträger entfernt werden');
        }
        return $this->json($response, ['message' => 'Anhang gelöscht']);
    }

    private function path(string $stored): string {
        if (str_starts_with($stored, 'legacy/')) {
            $name = substr($stored, 7);
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $name)) {
                throw new \RuntimeException('Ungültiger Dateipfad');
            }
            return __DIR__ . '/../../public/uploads/tasks/' . $name;
        }
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|png|jpg|jpeg|gif|docx|xlsx|txt)$/D', $stored)) {
            throw new \RuntimeException('Ungültiger Dateipfad');
        }
        return __DIR__ . '/../../storage/task_attachments/' . $stored;
    }
}
