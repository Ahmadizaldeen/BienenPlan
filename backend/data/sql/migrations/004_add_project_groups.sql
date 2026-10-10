-- Existing databases only: 000_schema.sql already includes this table.
CREATE TABLE projects_groups (
    project_id INT NOT NULL,
    group_id INT NOT NULL,
    PRIMARY KEY (project_id, group_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Preserve existing task access by allowing each formerly assigned group on its project.
INSERT INTO projects_groups (project_id, group_id)
SELECT DISTINCT c.project_id, gt.group_id -- ensure each group is linked to its project
FROM groups_tasks gt
JOIN tasks t ON t.id = gt.task_id
JOIN containers c ON c.id = t.container_id;