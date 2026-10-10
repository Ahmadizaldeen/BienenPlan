<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../data/sql/migrations/006_localize_project_groups.php';

use BienenPlan\Config\Database;
use BienenPlan\Services\Env;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

Env::load();
$db = Database::getConnection();
$originalDatabase = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$testDatabase = 'bienenplan_access_test_' . bin2hex(random_bytes(8));
$db->exec("CREATE DATABASE `$testDatabase` CHARACTER SET utf8mb4");
try {
    $db->exec("USE `$testDatabase`");
    $db->exec("CREATE TABLE users (id INT PRIMARY KEY) ENGINE=InnoDB;
        CREATE TABLE projects (id INT PRIMARY KEY) ENGINE=InnoDB;
        CREATE TABLE groups (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE,
            personal_user_id INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
        CREATE TABLE projects_groups (project_id INT, group_id INT, PRIMARY KEY (project_id, group_id)) ENGINE=InnoDB;
        ALTER TABLE projects ADD COLUMN archived_at DATETIME NULL;
        CREATE TABLE users_groups (user_id INT, groups_id INT, role VARCHAR(20), assignment_date DATETIME,
            PRIMARY KEY (user_id, groups_id)) ENGINE=InnoDB;
        CREATE TABLE containers (id INT PRIMARY KEY, project_id INT) ENGINE=InnoDB;
        CREATE TABLE tasks (id INT PRIMARY KEY, container_id INT) ENGINE=InnoDB;
        CREATE TABLE groups_tasks (task_id INT, group_id INT, PRIMARY KEY (task_id, group_id)) ENGINE=InnoDB;
        INSERT INTO users VALUES (1), (2);
        INSERT INTO projects (id) VALUES (1), (2);
        INSERT INTO groups (id, name, personal_user_id) VALUES (1, 'Local', NULL), (2, 'Shared legacy', NULL),
            (3, 'Unassigned', NULL), (4, 'Personal user 1', 1);
        INSERT INTO projects_groups VALUES (1, 1), (1, 2), (2, 2);
        INSERT INTO users_groups VALUES (1, 2, 'member', '2026-01-01'), (2, 2, 'owner', '2026-02-01'), (2, 3, 'member', '2026-03-01');
        INSERT INTO containers VALUES (1, 1), (2, 2);
        INSERT INTO tasks VALUES (1, 1), (2, 2);
        INSERT INTO groups_tasks VALUES (1, 2), (2, 2);");
    $migration = file_get_contents(__DIR__ . '/../data/sql/migrations/005_add_central_access.sql');
    if ($migration === false) throw new RuntimeException('Migration konnte nicht gelesen werden');
    $db->exec($migration);
    check((int) $db->query('SELECT COUNT(*) FROM users WHERE is_admin = 0')->fetchColumn() === 2, 'Migration grants no admin rights');
    check((int) $db->query('SELECT project_id FROM groups WHERE id = 1')->fetchColumn() === 1, 'Unambiguous legacy group becomes local');
    check((int) $db->query('SELECT COUNT(*) FROM groups WHERE project_id IS NULL AND is_global = 0')->fetchColumn() === 3, 'Shared, unassigned and personal groups not automatically global');
    check((int) $db->query('SELECT COUNT(*) FROM projects_groups')->fetchColumn() === 3, 'Existing assignments retained');
    $db->exec("INSERT INTO groups (id, name, project_id) VALUES (5, 'Local', 2)");
    try {
        $db->exec("INSERT INTO groups (id, name, project_id) VALUES (6, 'Local', 1)");
        throw new LogicException('Duplicate local name should fail');
    } catch (PDOException $exception) {
        check($exception->getCode() === '23000', 'Local names are unique per project');
    }
    try {
        $db->exec('UPDATE groups SET is_global = 1 WHERE id = 1');
        throw new LogicException('Mixed scope should fail');
    } catch (PDOException $exception) {
        check($exception->getCode() === '23000', 'Local/global scopes are exclusive');
    }
    $localize = new LocalGroupMigration($db);
    try {
        $localize->apply([]);
        throw new LogicException('Missing project choice should fail');
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), 'Project choice required'), 'Unassigned groups require explicit choice');
    }
    check(!$db->inTransaction(), 'Failed localization rolls back');
    $beforeTasks = (int) $db->query('SELECT COUNT(*) FROM groups_tasks')->fetchColumn();
    $result = $localize->apply([3 => 1]);
    check(count($result) === 3, 'Shared group split in two, unassigned group localized');
    $cloneId = (int) $db->query("SELECT id FROM groups WHERE name = 'Shared legacy' AND project_id = 2")->fetchColumn();
    check($cloneId !== 2 && $cloneId > 0, 'Second project receives independent group');
    check((int) $db->query("SELECT COUNT(*) FROM users_groups WHERE groups_id = $cloneId")->fetchColumn() === 2, 'All memberships copied');
    check((string) $db->query("SELECT assignment_date FROM users_groups WHERE groups_id = $cloneId AND user_id = 2")->fetchColumn() === '2026-02-01 00:00:00', 'Membership metadata preserved');
    check((int) $db->query('SELECT group_id FROM groups_tasks WHERE task_id = 1')->fetchColumn() === 2, 'First project keeps original group ID');
    check((int) $db->query('SELECT group_id FROM groups_tasks WHERE task_id = 2')->fetchColumn() === $cloneId, 'Second project tasks rewired to clone');
    check((int) $db->query('SELECT COUNT(*) FROM groups_tasks')->fetchColumn() === $beforeTasks, 'No task assignments lost');
    check((int) $db->query('SELECT COUNT(*) FROM projects_groups WHERE project_id = 1 AND group_id = 3')->fetchColumn() === 1, 'Unassigned group linked to chosen project');
    check($localize->apply([3 => 1]) === [], 'Localization rerun creates no duplicates');
    $db->exec("DELETE FROM users_groups WHERE user_id = 1 AND groups_id = $cloneId");
    check((int) $db->query('SELECT COUNT(*) FROM users_groups WHERE user_id = 1 AND groups_id = 2')->fetchColumn() === 1, 'Memberships are independent after splitting');
    check((int) $db->query('SELECT COUNT(*) FROM groups WHERE personal_user_id = 1 AND project_id IS NULL AND is_global = 0')->fetchColumn() === 1, 'Personal group remains unchanged');
    echo "Access migration tests passed\n";
} finally {
    $db->exec('USE `' . str_replace('`', '``', $originalDatabase) . '`');
    // Only the randomly named database created above is removed.
    $db->exec("DROP DATABASE `$testDatabase`");
}

$schemaDatabase = 'bienenplan_schema_test_' . bin2hex(random_bytes(8));
$db->exec("CREATE DATABASE `$schemaDatabase` CHARACTER SET utf8mb4");
try {
    $db->exec("USE `$schemaDatabase`");
    $schema = file_get_contents(__DIR__ . '/../data/sql/migrations/000_schema.sql');
    if ($schema === false) throw new RuntimeException('Schema konnte nicht gelesen werden');
    $start = strpos($schema, 'CREATE TABLE users');
    if ($start === false) throw new RuntimeException('Schema-Anfang fehlt');
    // Never execute the development DROP DATABASE / USE header.
    $db->exec(substr($schema, $start));
    $columns = $db->query('SHOW COLUMNS FROM groups')->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('project_id', $columns, true) && in_array('is_global', $columns, true), 'Fresh schema includes group scope');
    echo "Fresh access schema tests passed\n";
} finally {
    $db->exec('USE `' . str_replace('`', '``', $originalDatabase) . '`');
    $db->exec("DROP DATABASE `$schemaDatabase`");
}
