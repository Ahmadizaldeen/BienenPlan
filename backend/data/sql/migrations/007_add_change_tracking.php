<?php

final class ChangeTrackingMigration {
    public const KEYS = [
        'users' => ['id'],
        'projects' => ['id'],
        'containers' => ['id'],
        'tasks' => ['id'],
        'groups' => ['id'],
        'subtasks' => ['id'],
        'task_attachments' => ['id'],
        'users_groups' => ['user_id', 'groups_id'],
        'projects_groups' => ['project_id', 'group_id'],
        'groups_tasks' => ['group_id', 'task_id'],
    ];

    public function __construct(private PDO $db) {}

    private function key(array $columns, string $prefix = ''): string {
        return "CONCAT_WS(':', " . implode(', ', array_map(
            static fn(string $column): string => $prefix . "`$column`",
            $columns
        )) . ')';
    }

    public function apply(): void {
        // DDL implicitly commits in MySQL; run this migration in a maintenance window.
        if ($this->db->inTransaction()) {
            throw new LogicException('Change tracking migration requires no active transaction');
        }
        $columns = [];
        foreach (self::KEYS as $table => $keys) {
            $columns[$table] = $this->db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            if (array_diff($keys, $columns[$table])) {
                throw new RuntimeException("Missing change tracking key in $table");
            }
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS change_stamps (
            entity VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            record_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY (entity, record_key)
        ) ENGINE=InnoDB');

        foreach (self::KEYS as $table => $keys) {
            $newKey = $this->key($keys, 'NEW.');
            $oldKey = $this->key($keys, 'OLD.');
            $stamp = "INSERT INTO change_stamps (entity, record_key, revision)
                VALUES ('$table', $newKey, UUID())
                ON DUPLICATE KEY UPDATE revision = VALUES(revision);";
            $equal = implode(' AND ', array_map(
                static fn(string $column): string =>
                    '(OLD.`' . str_replace('`', '``', $column) . '` <=> NEW.`' . str_replace('`', '``', $column) . '`)',
                $columns[$table]
            ));
            foreach (['insert', 'update', 'delete'] as $operation) {
                $name = "changes_{$table}_{$operation}";
                $this->db->exec("DROP TRIGGER IF EXISTS `$name`");
                $body = match ($operation) {
                    'insert' => $stamp,
                    'update' => "IF NOT ($equal) THEN
                        DELETE FROM change_stamps WHERE entity = '$table' AND record_key = $oldKey;
                        $stamp END IF;",
                    'delete' => "DELETE FROM change_stamps WHERE entity = '$table' AND record_key = $oldKey;",
                };
                $this->db->exec("CREATE TRIGGER `$name` AFTER " . strtoupper($operation) . " ON `$table`
                    FOR EACH ROW BEGIN $body END");
            }
            $key = $this->key($keys);
            $this->db->exec("INSERT IGNORE INTO change_stamps (entity, record_key, revision)
                SELECT '$table', $key, UUID() FROM `$table`");
            // Foreign-key cascades do not execute MySQL triggers.
            $this->db->exec("DELETE s FROM change_stamps s
                LEFT JOIN `$table` r ON s.record_key = " . $this->key($keys, 'r.') . "
                WHERE s.entity = '$table' AND r.`{$keys[0]}` IS NULL");
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__ . '/../../../vendor/autoload.php';
    BienenPlan\Services\Env::load();
    (new ChangeTrackingMigration(BienenPlan\Config\Database::getConnection()))->apply();
    echo "Change tracking migration applied\n";
}
