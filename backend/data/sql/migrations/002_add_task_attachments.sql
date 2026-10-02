USE `bienenplan`;

CREATE TABLE IF NOT EXISTS task_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    uploaded_by INT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_task_attachments_task (task_id, deleted_at),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alte, bereits gespeicherte Uploads bleiben auf Disk und werden über den
-- authentifizierten Download-Endpunkt gelesen. Der Webserver sperrt den Ordner.
INSERT INTO task_attachments (task_id, uploaded_by, original_name, stored_name, mime_type, size_bytes)
SELECT t.id, t.created_by, SUBSTRING_INDEX(t.attachment, '/', -1),
       CONCAT('legacy/', SUBSTRING_INDEX(t.attachment, '/', -1)),
       'application/octet-stream', 0
FROM tasks t
WHERE t.attachment REGEXP '^uploads/tasks/[a-zA-Z0-9_.-]+$'
  AND NOT EXISTS (
      SELECT 1 FROM task_attachments a
      WHERE a.stored_name = CONCAT('legacy/', SUBSTRING_INDEX(t.attachment, '/', -1))
  );
