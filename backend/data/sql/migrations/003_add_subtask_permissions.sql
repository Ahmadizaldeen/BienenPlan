-- Legacy subtasks intentionally keep a NULL creator; owners can still manage them.
ALTER TABLE subtasks
    ADD COLUMN created_by INT NULL,
    ADD COLUMN deleted_at DATETIME NULL,
    ADD COLUMN deleted_by INT NULL,
    ADD INDEX idx_subtasks_task_deleted (task_id, deleted_at),
    ADD CONSTRAINT fk_subtasks_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_subtasks_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;