<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../data/sql/migrations/007_add_change_tracking.php';

use BienenPlan\Config\Database;
use BienenPlan\Controllers\ChangeController;
use BienenPlan\Services\ChangeFeed;
use BienenPlan\Services\Env;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function entities(array $result): array {
    return array_column($result['changes'], 'entity');
}

Env::load();
$db = Database::getConnection();
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$originalDatabase = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$testDatabase = 'bienenplan_changes_test_' . bin2hex(random_bytes(8));
$db->exec("CREATE DATABASE `$testDatabase` CHARACTER SET utf8mb4");
$other = null;
try {
    $db->exec("USE `$testDatabase`");
    $schema = file_get_contents(__DIR__ . '/../data/sql/migrations/000_schema.sql');
    if ($schema === false || ($start = strpos($schema, 'CREATE TABLE users')) === false) {
        throw new RuntimeException('Schema could not be read');
    }
    // Never execute the destructive development schema header.
    $db->exec(substr($schema, $start));
    $db->exec("INSERT INTO users (id, name, email, password_hash, is_admin) VALUES
        (1, 'Owner', 'owner@example.invalid', 'test', 0),
        (2, 'Member', 'member@example.invalid', 'test', 0),
        (3, 'Stranger', 'stranger@example.invalid', 'test', 0),
        (4, 'Admin', 'admin@example.invalid', 'test', 1);
        INSERT INTO projects (id, name, created_by) VALUES (1, 'Shared', 1), (2, 'Hidden', 3);
        INSERT INTO containers (id, title, project_id, created_by) VALUES
            (1, 'Visible container', 1, 1), (2, 'Hidden container', 2, 3), (3, 'Second container', 1, 1);
        INSERT INTO groups (id, name, project_id, personal_user_id) VALUES
            (1, 'Visible group', 1, NULL), (2, 'Hidden group', 2, NULL),
            (3, 'Personal user 2', NULL, 2), (4, 'Personal user 1', NULL, 1);
        INSERT INTO projects_groups VALUES (1, 1), (2, 2);
        INSERT INTO users_groups (user_id, groups_id) VALUES (2, 1), (3, 2), (2, 3), (1, 4);
        INSERT INTO tasks (id, container_id, created_by, title) VALUES
            (1, 1, 2, 'Shared task'), (2, 2, 3, 'Hidden task'), (3, 1, 1, 'Private task');
        INSERT INTO groups_tasks VALUES (1, 1), (3, 1), (2, 2), (4, 3);
        INSERT INTO subtasks (id, task_id, title, created_by) VALUES (1, 1, 'Visible subtask', 2), (2, 2, 'Hidden subtask', 3);
        INSERT INTO task_attachments (id, task_id, uploaded_by, original_name, stored_name, mime_type, size_bytes)
            VALUES (1, 1, 2, 'Plan.txt', 'visible.txt', 'text/plain', 1),
                   (2, 2, 3, 'Secret.txt', 'hidden.txt', 'text/plain', 1)");
    $migration = new ChangeTrackingMigration($db);
    $migration->apply();
    check((int) $db->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()")->fetchColumn() === 30,
        'All ten tables have insert/update/delete tracking');
    check((int) $db->query('SELECT COUNT(*) FROM change_stamps')->fetchColumn() > 0, 'Existing rows are backfilled');
    $feed = new ChangeFeed($db, 'change-feed-test-key');
    $baseline = $feed->poll(2);
    $cursor = $baseline['next_cursor'];
    check(!$baseline['refresh_required'] && $baseline['changes'] === [], 'First request establishes a baseline');
    check($feed->poll(2, $cursor)['next_cursor'] === $cursor, 'Unchanged snapshot keeps the cursor stable');
    $migration->apply();
    check($feed->poll(2, $cursor)['next_cursor'] === $cursor, 'Migration rerun preserves existing revisions');

    $mutations = [
        'users' => "UPDATE users SET picture = 'new-picture.png' WHERE id = 2",
        'projects' => "UPDATE projects SET name = 'Renamed project' WHERE id = 1",
        'containers' => "UPDATE containers SET title = 'Renamed container' WHERE id = 1",
        'tasks' => "UPDATE tasks SET status = 'done' WHERE id = 1",
        'groups' => "UPDATE groups SET name = 'Renamed group' WHERE id = 1",
        'subtasks' => 'UPDATE subtasks SET completed = 1 WHERE id = 1',
        'task_attachments' => "UPDATE task_attachments SET original_name = 'Updated.txt' WHERE id = 1",
        'users_groups' => "UPDATE users_groups SET role = 'admin' WHERE user_id = 2 AND groups_id = 1",
        'projects_groups' => 'DELETE FROM projects_groups WHERE project_id = 1 AND group_id = 1',
        'groups_tasks' => 'DELETE FROM groups_tasks WHERE task_id = 1 AND group_id = 3',
    ];
    foreach ($mutations as $entity => $sql) {
        $before = $feed->poll(1)['next_cursor'];
        // Profile changes must be tested as that profile's owner.
        if ($entity === 'users') $before = $feed->poll(2)['next_cursor'];
        $db->exec($sql);
        $changed = $feed->poll($entity === 'users' ? 2 : 1, $before);
        check($changed['refresh_required'] && in_array($entity, entities($changed), true), "$entity changes are detected");
        check(!$feed->poll($entity === 'users' ? 2 : 1, $changed['next_cursor'])['refresh_required'], "$entity cursor can be acknowledged");
    }
    $db->exec('INSERT INTO projects_groups VALUES (1, 1); INSERT INTO groups_tasks VALUES (3, 1)');

    $insertions = [
        'users' => "INSERT INTO users (id, name, email, password_hash) VALUES (20, 'New user', 'new@example.invalid', 'test')",
        'projects' => "INSERT INTO projects (id, name, created_by) VALUES (20, 'New project', 1)",
        'containers' => "INSERT INTO containers (id, title, project_id, created_by) VALUES (20, 'New container', 1, 1)",
        'tasks' => "INSERT INTO tasks (id, container_id, created_by, title) VALUES (20, 1, 1, 'New task')",
        'groups' => "INSERT INTO groups (id, name, project_id) VALUES (20, 'New group', 1)",
        'subtasks' => "INSERT INTO subtasks (id, task_id, title, created_by) VALUES (20, 1, 'New subtask', 1)",
        'task_attachments' => "INSERT INTO task_attachments (id, task_id, uploaded_by, original_name, stored_name, mime_type, size_bytes)
            VALUES (20, 1, 1, 'New.txt', 'new.txt', 'text/plain', 1)",
        'users_groups' => 'INSERT INTO users_groups (user_id, groups_id) VALUES (20, 20)',
        'projects_groups' => 'INSERT INTO projects_groups VALUES (1, 20)',
        'groups_tasks' => 'INSERT INTO groups_tasks VALUES (20, 1)',
    ];
    foreach ($insertions as $entity => $sql) {
        $before = $feed->poll(1)['next_cursor'];
        $db->exec($sql);
        check(in_array($entity, entities($feed->poll(1, $before)), true), "$entity inserts are detected");
    }
    $deletions = [
        'groups_tasks' => 'DELETE FROM groups_tasks WHERE group_id = 20',
        'projects_groups' => 'DELETE FROM projects_groups WHERE group_id = 20',
        'users_groups' => 'DELETE FROM users_groups WHERE groups_id = 20',
        'task_attachments' => 'DELETE FROM task_attachments WHERE id = 20',
        'subtasks' => 'DELETE FROM subtasks WHERE id = 20',
        'groups' => 'DELETE FROM groups WHERE id = 20',
        'tasks' => 'DELETE FROM tasks WHERE id = 20',
        'containers' => 'DELETE FROM containers WHERE id = 20',
        'projects' => 'DELETE FROM projects WHERE id = 20',
        'users' => 'DELETE FROM users WHERE id = 20',
    ];
    foreach ($deletions as $entity => $sql) {
        $before = $feed->poll(1)['next_cursor'];
        $db->exec($sql);
        check(in_array($entity, entities($feed->poll(1, $before)), true), "$entity deletes are detected");
    }

    $before = $feed->poll(2)['next_cursor'];
    $db->exec("UPDATE tasks SET title = 'Secret changed' WHERE id IN (2, 3);
        UPDATE containers SET title = 'Secret container changed' WHERE id = 2;
        UPDATE subtasks SET title = 'Secret subtask changed' WHERE id = 2;
        UPDATE task_attachments SET original_name = 'Hidden-new.txt' WHERE id = 2;
        UPDATE groups SET name = 'Secret group changed' WHERE id = 2;
        UPDATE projects SET name = 'Hidden renamed' WHERE id = 2;
        UPDATE users SET password_hash = 'private-password-change' WHERE id = 3");
    check(!$feed->poll(2, $before)['refresh_required'], 'Hidden contents and private password changes do not leak through polling');

    $before = $feed->poll(1)['next_cursor'];
    // Preserve the auto-update timestamp too, so this is a genuinely unchanged row.
    $db->exec('UPDATE tasks SET status = status, updated_at = updated_at WHERE id = 1');
    check(!$feed->poll(1, $before)['refresh_required'], 'Unchanged rows do not trigger a refresh');
    $db->exec("UPDATE tasks SET status = 'in_progress', updated_at = updated_at WHERE id = 1;
        UPDATE tasks SET status = 'done', updated_at = updated_at WHERE id = 1");
    check(in_array('tasks', entities($feed->poll(1, $before)), true),
        'Multiple changes with an unchanged updated_at are detected even when content returns to its old value');
    $db->exec("INSERT INTO tasks (id, container_id, created_by, title) VALUES (10, 1, 1, 'Inserted')");
    check(in_array('tasks', entities($feed->poll(1, $before)), true), 'Task inserts are detected');
    $before = $feed->poll(1)['next_cursor'];
    $db->exec('DELETE FROM tasks WHERE id = 10');
    check(in_array('tasks', entities($feed->poll(1, $before)), true), 'Hard deletes are detected');
    check((int) $db->query("SELECT COUNT(*) FROM change_stamps WHERE entity = 'tasks' AND record_key = '10'")->fetchColumn() === 0,
        'Hard-deleted row stamps are removed');
    $before = $feed->poll(2)['next_cursor'];
    $db->exec('UPDATE subtasks SET deleted_at = NOW(), deleted_by = 2 WHERE id = 1');
    check(in_array('subtasks', entities($feed->poll(2, $before)), true), 'Subtask soft deletes are detected');
    $before = $feed->poll(2)['next_cursor'];
    $db->exec('UPDATE task_attachments SET deleted_at = NOW() WHERE id = 1');
    check(in_array('task_attachments', entities($feed->poll(2, $before)), true), 'Attachment soft deletes are detected');

    $before = $feed->poll(2)['next_cursor'];
    $db->exec('DELETE FROM users_groups WHERE user_id = 2 AND groups_id = 1;
        DELETE FROM groups_tasks WHERE group_id = 3 AND task_id = 1');
    $revoked = $feed->poll(2, $before);
    check($revoked['refresh_required'] && in_array('tasks', entities($revoked), true)
        && in_array('projects', entities($revoked), true), 'Loss of project and task access invalidates cached rows');
    check(!str_contains(json_encode($revoked), 'Secret'), 'Response contains no entity data');
    $db->exec('INSERT INTO users_groups (user_id, groups_id) VALUES (2, 1); INSERT INTO groups_tasks VALUES (3, 1)');
    $before = $feed->poll(2)['next_cursor'];
    $db->exec('UPDATE tasks SET container_id = 3 WHERE id = 1');
    check(in_array('tasks', entities($feed->poll(2, $before)), true), 'Task moves are detected');
    $before = $feed->poll(2)['next_cursor'];
    $db->exec('UPDATE projects SET archived_at = NOW(), archived_by = 1 WHERE id = 1');
    check(in_array('tasks', entities($feed->poll(2, $before)), true), 'Archiving removes member-visible task contents');
    $before = $feed->poll(4)['next_cursor'];
    $db->exec('UPDATE projects SET archived_at = NULL, archived_by = NULL WHERE id = 1');
    check(in_array('projects', entities($feed->poll(4, $before)), true), 'Restoring archived projects is detected for admins');
    $before = $feed->poll(2)['next_cursor'];
    $db->exec('UPDATE users SET is_admin = 1 WHERE id = 2');
    check(in_array('users', entities($feed->poll(2, $before)), true), 'Admin grants update profile and visibility');
    $before = $feed->poll(2)['next_cursor'];
    $db->exec('UPDATE users SET is_admin = 0 WHERE id = 2');
    check(in_array('tasks', entities($feed->poll(2, $before)), true), 'Admin revocation removes previously visible hidden tasks');

    // Independent commits are not reduced to MAX(auto_increment): no late-commit gap.
    $dsn = "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname=$testDatabase;charset=utf8mb4";
    $other = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $otherFeed = new ChangeFeed($other, 'change-feed-test-key');
    $before = $otherFeed->poll(1)['next_cursor'];
    $db->beginTransaction();
    $db->exec("UPDATE tasks SET title = 'Pending task update' WHERE id = 1");
    check(!$otherFeed->poll(1, $before)['refresh_required'], 'Uncommitted writes are invisible');
    $other->exec("UPDATE containers SET title = 'Independent committed update' WHERE id = 3");
    $middle = $otherFeed->poll(1, $before);
    check(in_array('containers', entities($middle), true), 'Independent transaction commits while task change is pending');
    $db->commit();
    check(in_array('tasks', entities($otherFeed->poll(1, $middle['next_cursor'])), true), 'Later commit is detected even after advancing the cursor');
    $before = $otherFeed->poll(1)['next_cursor'];
    $db->beginTransaction();
    $db->exec("UPDATE tasks SET title = 'Rolled back' WHERE id = 1");
    $db->rollBack();
    check(!$otherFeed->poll(1, $before)['refresh_required'], 'Row changes and stamps roll back together');
    $before = $feed->poll(1)['next_cursor'];
    $db->exec("INSERT INTO users (id, name, email, password_hash) VALUES (30, 'Cascade', 'cascade@example.invalid', 'test');
        INSERT INTO groups (id, name, personal_user_id) VALUES (30, 'Personal user 30', 30);
        INSERT INTO tasks (id, container_id, created_by, title) VALUES (30, 1, 30, 'Cascade task');
        INSERT INTO groups_tasks VALUES (30, 30)");
    $inserted = $feed->poll(1, $before);
    check($inserted['refresh_required'], 'Cascade fixture is visible');
    $db->exec('DELETE FROM users WHERE id = 30');
    $cascaded = $feed->poll(1, $inserted['next_cursor']);
    check(in_array('groups_tasks', entities($cascaded), true) && in_array('tasks', entities($cascaded), true),
        'Foreign-key CASCADE and SET NULL invalidate contents without child triggers');
    $migration->apply();
    check((int) $db->query("SELECT COUNT(*) FROM change_stamps WHERE entity = 'groups' AND record_key = '30'")->fetchColumn() === 0,
        'Migration rerun prunes orphaned cascade stamps');

    $controller = new ChangeController($feed);
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/changes')->withAttribute('user_id', 2);
    $response = $controller->poll($request, new Response());
    check($response->getStatusCode() === 200 && $response->getHeaderLine('Cache-Control') === 'no-store', 'Polling responses are not cacheable');
    foreach (['', 'tampered', [$cursor], substr($cursor, 0, -1) . 'z'] as $invalid) {
        check($controller->poll($request->withQueryParams(['after' => $invalid]), new Response())->getStatusCode() === 400,
            'Malformed cursor returns 400');
    }
    $ownerCursor = $feed->poll(1)['next_cursor'];
    check($controller->poll($request->withQueryParams(['after' => $ownerCursor]), new Response())->getStatusCode() === 400,
        'Cursor is bound to its authenticated user');
    $db->exec('UPDATE users SET deleted_at = NOW() WHERE id = 2');
    check($controller->poll($request, new Response())->getStatusCode() === 401, 'Deleted accounts cannot poll');
    $route = file_get_contents(__DIR__ . '/../public/index.php');
    check($route !== false && str_contains($route, "\$group->get('/changes', [\$changeController, 'poll'])"),
        'Route is registered inside the authenticated group');
    echo "Change feed tests passed (tracking, authorization, invalidation, rollback, concurrent commits, cursors)\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($other !== null && $other->inTransaction()) $other->rollBack();
    $other = null;
    $db->exec('USE `' . str_replace('`', '``', $originalDatabase) . '`');
    $db->exec("DROP DATABASE `$testDatabase`");
}
