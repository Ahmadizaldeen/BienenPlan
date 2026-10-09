-- Apply once after 004; MySQL/MariaDB DDL is not rolled back as one transaction.
ALTER TABLE users ADD COLUMN is_admin BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE groups
    ADD COLUMN project_id INT NULL,
    ADD COLUMN is_global BOOLEAN NOT NULL DEFAULT FALSE,
    ADD CONSTRAINT fk_groups_project FOREIGN KEY (project_id) REFERENCES projects(id),
    ADD CONSTRAINT chk_groups_scope CHECK (
        (personal_user_id IS NULL OR (project_id IS NULL AND is_global = FALSE))
        AND (project_id IS NULL OR is_global = FALSE)
    );

-- Only unambiguous legacy project groups become local.
-- Unassigned/multi-project groups remain unclassified until explicitly reviewed.
UPDATE groups g
JOIN (
    SELECT group_id, MIN(project_id) AS project_id
    FROM projects_groups GROUP BY group_id HAVING COUNT(*) = 1
) existing_scope ON existing_scope.group_id = g.id
SET g.project_id = existing_scope.project_id
WHERE g.personal_user_id IS NULL;

ALTER TABLE groups
    DROP INDEX name,
    ADD COLUMN name_scope INT GENERATED ALWAYS AS (COALESCE(project_id, 0)) STORED,
    ADD UNIQUE KEY uq_groups_scope_name (name_scope, name);
