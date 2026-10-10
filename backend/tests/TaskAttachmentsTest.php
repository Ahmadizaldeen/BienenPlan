<?php

require __DIR__ . '/../vendor/autoload.php';

use BienenPlan\Controllers\TaskAttachmentController;
use BienenPlan\Models\TaskAttachment;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\StreamFactory;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fileUpload(string $name, string $content, ?stdClass $tracker = null): UploadedFileInterface {
    return new class($name, $content, $tracker) implements UploadedFileInterface {
        private StreamInterface $stream;

        public function __construct(private string $name, string $content, private ?stdClass $tracker) {
            $this->stream = (new StreamFactory())->createStream($content);
        }

        public function getStream(): StreamInterface { return $this->stream; }
        public function moveTo(string $targetPath): void {
            if ($this->tracker !== null) $this->tracker->path = $targetPath;
            file_put_contents($targetPath, (string) $this->stream);
        }
        public function getSize(): ?int { return $this->stream->getSize(); }
        public function getError(): int { return UPLOAD_ERR_OK; }
        public function getClientFilename(): ?string { return $this->name; }
        public function getClientMediaType(): ?string { return null; }
    };
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, deleted_at TEXT, is_admin INTEGER DEFAULT 0)');
$db->exec('INSERT INTO users (id) VALUES (1), (2), (3)');
$db->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, created_by INTEGER, archived_at TEXT)');
$db->exec('CREATE TABLE containers (id INTEGER PRIMARY KEY, project_id INTEGER, created_by INTEGER, deleted_at TEXT)');
$db->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, container_id INTEGER, deleted_at TEXT, created_by INTEGER)');
$db->exec('CREATE TABLE groups (id INTEGER PRIMARY KEY, personal_user_id INTEGER, project_id INTEGER, is_global INTEGER DEFAULT 0)');
$db->exec('INSERT INTO groups VALUES (1, NULL, 1, 0)');
$db->exec('CREATE TABLE groups_tasks (task_id INTEGER, group_id INTEGER)');
$db->exec('CREATE TABLE projects_groups (project_id INTEGER, group_id INTEGER)');
$db->exec('CREATE TABLE users_groups (user_id INTEGER, groups_id INTEGER)');
$db->exec('CREATE TABLE task_attachments (
    id INTEGER PRIMARY KEY, task_id INTEGER, uploaded_by INTEGER, original_name TEXT,
    stored_name TEXT, mime_type TEXT, size_bytes INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    deleted_at TEXT
)');
$db->exec('INSERT INTO projects (id, created_by) VALUES (1, 1)');
$db->exec('INSERT INTO containers (id, project_id, created_by) VALUES (1, 1, 2)');
$db->exec('INSERT INTO tasks (id, container_id) VALUES (1, 1)');
$db->exec('INSERT INTO groups_tasks VALUES (1, 1)');
$db->exec('INSERT INTO projects_groups VALUES (1, 1)');
$db->exec('INSERT INTO users_groups VALUES (3, 1)');
$model = new TaskAttachment($db);
$controller = new TaskAttachmentController($model);
$args = ['id' => '1'];
$request = (new ServerRequestFactory())->createServerRequest('POST', '/')
    ->withAttribute('user_id', 3)
    ->withUploadedFiles(['files' => [
        fileUpload('C:\\temp\\one.txt', 'First file'),
        fileUpload('two.txt', 'Second file'),
    ]]);
$response = $controller->upload($request, new Response(), $args);
check($response->getStatusCode() === 201, 'Batch upload must succeed');
$items = json_decode((string) $response->getBody(), true)['attachments'];
check(count($items) === 2, 'Both files must be persisted');
check($items[0]['original_name'] === 'one.txt', 'Client path must not be kept as filename');
check($items[0]['can_delete'] == 1, 'Uploader can delete');

$db->exec('INSERT INTO users (id) VALUES (4); INSERT INTO users_groups VALUES (4, 1)');
$groupMember = $request->withAttribute('user_id', 4);
$memberArgs = $args + ['attachmentId' => (string) $items[0]['id']];
check($model->access()->canEditTask(4, 1), 'Assigned local-group member can edit task fields');
check($controller->index($groupMember, new Response(), $args)->getStatusCode() === 200, 'Other assigned group member can list attachments');
check((string) $controller->download($groupMember, new Response(), $memberArgs)->getBody() === 'First file', 'Other assigned group member can download uploader attachment');
check($controller->delete($groupMember, new Response(), $memberArgs)->getStatusCode() === 403, 'Assignment does not grant deletion of another member attachment');
$memberUpload = $groupMember->withUploadedFiles(['files' => [fileUpload('member.txt', 'Member upload')]]);
$memberResponse = $controller->upload($memberUpload, new Response(), $args);
check($memberResponse->getStatusCode() === 201, 'Assigned member without task edit permission can upload');
$memberItem = json_decode((string) $memberResponse->getBody(), true)['attachments'][0];
$memberAttachmentArgs = $args + ['attachmentId' => (string) $memberItem['id']];
$db->exec('DELETE FROM users_groups WHERE user_id = 4');
check($controller->download($groupMember, new Response(), $memberArgs)->getStatusCode() === 404, 'Revoked membership denies attachment download');
check($controller->upload($memberUpload, new Response(), $args)->getStatusCode() === 404, 'Revoked membership denies attachment upload');
$db->exec('INSERT INTO users_groups VALUES (4, 1)');
check($controller->delete($groupMember, new Response(), $memberAttachmentArgs)->getStatusCode() === 200, 'Member can delete own upload');

$db->exec('INSERT INTO groups (id, personal_user_id, project_id, is_global) VALUES (2, 2, NULL, 0);
    INSERT INTO tasks (id, container_id, created_by) VALUES (2, 1, 1);
    INSERT INTO groups_tasks (task_id, group_id) VALUES (2, 2)');
$personalAssignee = $request->withAttribute('user_id', 2);
check(!$model->access()->canViewProject(2, 1) && $model->access()->canViewTask(2, 2),
    'Assigned personal group grants task access without project access');
$personalUploadRequest = $personalAssignee->withUploadedFiles([
    'files' => [fileUpload('personal.txt', 'Personal assignee upload')],
]);
$personalUpload = $controller->upload($personalUploadRequest, new Response(), ['id' => '2']);
check($personalUpload->getStatusCode() === 201, 'Personal assignee may add task attachments');
$personalAttachment = json_decode((string) $personalUpload->getBody(), true)['attachments'][0];
check($controller->delete(
    $personalAssignee,
    new Response(),
    ['id' => '2', 'attachmentId' => (string) $personalAttachment['id']]
)->getStatusCode() === 200, 'Uploader may remove own attachment without gaining broader task-delete rights');
check($controller->index($personalAssignee, new Response(), ['id' => '1'])->getStatusCode() === 404,
    'Personal assignment does not grant access to other project tasks');

$uploadRace = new class($db) extends TaskAttachment {
    public function __construct(private PDO $database) { parent::__construct($database); }
    public function add(int $taskId, int $userId, string $name, string $stored, string $mime, int $size): int {
        $this->database->exec('UPDATE projects SET archived_at = CURRENT_TIMESTAMP WHERE id = 1');
        return parent::add($taskId, $userId, $name, $stored, $mime, $size);
    }
};
$tracker = new stdClass();
$raceUploadRequest = $request->withUploadedFiles(['files' => [fileUpload('race.txt', 'Concurrent upload', $tracker)]]);
check((new TaskAttachmentController($uploadRace))->upload($raceUploadRequest, new Response(), $args)->getStatusCode() === 409, 'Archival during upload returns conflict');
check(count($model->byTask(1, 1, 1)) === 2 && !$db->inTransaction(), 'Denied upload rolls back database changes and closes transaction');
check(isset($tracker->path) && !is_file($tracker->path), 'Denied upload removes the moved file');

$deleteRace = new class($db) extends TaskAttachment {
    public function __construct(private PDO $database) { parent::__construct($database); }
    public function accessibleTask(int $taskId, int $userId): ?array {
        $task = parent::accessibleTask($taskId, $userId);
        if ($task !== null) {
            $this->database->exec('UPDATE projects SET archived_at = CURRENT_TIMESTAMP WHERE id = 1');
        }
        return $task;
    }
};
$raceDeleteArgs = $args + ['attachmentId' => (string) $items[0]['id']];
check((new TaskAttachmentController($deleteRace))->delete($request, new Response(), $raceDeleteArgs)->getStatusCode() === 409, 'Attachment deletion rejects archival after initial access check');
check($model->byId(1, (int) $items[0]['id']) !== null, 'Denied deletion preserves attachment record');
$db->exec('UPDATE projects SET archived_at = NULL WHERE id = 1');
check((string) $controller->download($request, new Response(), $raceDeleteArgs)->getBody() === 'First file', 'Denied deletion preserves attachment bytes');

check($model->accessibleTask(1, 99) === null, 'Unrelated user must not access task');
check($model->accessibleTask(1, 2) === null, 'Container owner cannot bypass task group visibility');
check($model->byTask(1, 2, 1)[0]['can_delete'] == 0, 'Container owner cannot delete');
check($model->byTask(1, 1, 1)[0]['can_delete'] == 1, 'Project owner can delete');

$invalid = $request->withUploadedFiles(['files' => [
    fileUpload('valid.txt', 'Valid'),
    fileUpload('invalid.pdf', 'Not a PDF'),
]]);
$response = $controller->upload($invalid, new Response(), $args);
check($response->getStatusCode() === 400, 'Invalid batch must fail');
check(count($model->byTask(1, 1, 1)) === 2, 'Invalid batch must not save any file');
$oversized = $request->withUploadedFiles(['files' => [fileUpload('large.txt', str_repeat('a', 10 * 1024 * 1024 + 1))]]);
check($controller->upload($oversized, new Response(), ['id' => '1'])->getStatusCode() === 400, 'Existing 10 MB limit must hold');
check(count($model->byTask(1, 1, 1)) === 2, 'Oversized file must not be stored');

$args['attachmentId'] = (string) $items[0]['id'];
$db->exec('UPDATE users SET is_admin = 1 WHERE id = 2; UPDATE projects SET archived_at = CURRENT_TIMESTAMP WHERE id = 1');
$archiveAdmin = $request->withAttribute('user_id', 2);
check($controller->download($archiveAdmin, new Response(), $args)->getBody()->__toString() === 'First file', 'Admin downloads archived attachment');
check($controller->download($request, new Response(), $args)->getStatusCode() === 404, 'Member cannot download archived attachment');
check($controller->delete($archiveAdmin, new Response(), $args)->getStatusCode() === 403, 'Admin cannot delete archived attachment');
check($model->byTask(1, 2, 1)[0]['can_delete'] == 0, 'Archive attachment capability is read-only');
$db->exec('UPDATE projects SET archived_at = NULL WHERE id = 1; UPDATE users SET is_admin = 0 WHERE id = 2');
$response = $controller->download($request, new Response(), $args);
check((string) $response->getBody() === 'First file', 'Download returns original bytes');
$other = $request->withAttribute('user_id', 2);
check($controller->delete($other, new Response(), $args)->getStatusCode() === 404, 'Container creator without task access cannot see attachments');
$stranger = $request->withAttribute('user_id', 99);
check($controller->download($stranger, new Response(), $args)->getStatusCode() === 404, 'Strangers cannot download');
check($controller->upload($stranger, new Response(), ['id' => '1'])->getStatusCode() === 404, 'Strangers cannot upload');
check($controller->delete($request, new Response(), $args)->getStatusCode() === 200, 'Uploader can delete');
check($controller->download($request, new Response(), $args)->getStatusCode() === 404, 'Deleted file cannot be downloaded');
$args['attachmentId'] = (string) $items[1]['id'];
check($controller->delete($request->withAttribute('user_id', 1), new Response(), $args)->getStatusCode() === 200, 'Project owner can delete');
echo "Task attachment tests passed\n";
