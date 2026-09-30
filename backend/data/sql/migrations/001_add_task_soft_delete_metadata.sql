USE `bienenplan`;

SET @migration_sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tasks ADD COLUMN deleted_by INT NULL AFTER deleted_at',
        'SELECT 1'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tasks'
      AND column_name = 'deleted_by'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tasks ADD INDEX idx_tasks_deleted_at (deleted_at)',
        'SELECT 1'
    )
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tasks'
      AND index_name = 'idx_tasks_deleted_at'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tasks ADD CONSTRAINT fk_tasks_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL',
        'SELECT 1'
    )
    FROM information_schema.key_column_usage
    WHERE table_schema = DATABASE()
      AND table_name = 'tasks'
      AND column_name = 'deleted_by'
      AND referenced_table_name = 'users'
      AND referenced_column_name = 'id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
