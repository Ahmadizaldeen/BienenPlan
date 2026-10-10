DROP DATABASE IF EXISTS `bienenplan`; -- FÜR ENTWICKLUNGSPHASE
CREATE DATABASE IF NOT EXISTS `bienenplan`;
USE `bienenplan`;

-- ============================================
-- USERS
-- ============================================
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_admin      BOOLEAN NOT NULL DEFAULT FALSE, -- direktes Admin-Rechte, Entwicklungshilfe 
    picture       VARCHAR(255), -- URL zu Profilbild
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL -- Soft Delete für das MVP.
-- Später: personenbezogene Daten nach definiertem Lösch-/Anonymisierungskonzept
-- gemäß DSGVO verarbeiten und, soweit rechtlich zulässig, anonymisieren.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- GROUPS
-- jeder User hat seine einge- oder mehrere Gruppen
-- ============================================
CREATE TABLE groups (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL, -- Gruppenname kann mehrfach vorkommen
    personal_user_id INT NULL UNIQUE, -- Verweis auf eigene persönliche Gruppe
    project_id INT NULL,
    is_global BOOLEAN NOT NULL DEFAULT FALSE, 
    name_scope INT GENERATED ALWAYS AS (COALESCE(project_id, 0)) STORED, -- Scope für eindeutige Namensgebung innerhalb eines Projekts
    UNIQUE KEY uq_groups_scope_name (name_scope, name),
    CONSTRAINT chk_groups_scope CHECK (
        (personal_user_id IS NULL OR (project_id IS NULL AND is_global = FALSE))
        AND (project_id IS NULL OR is_global = FALSE)
    ),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (personal_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- USERS_GROUPS (n:m relationship)
-- Ein Benutzer angehört zu m Gruppen, Gruppen etweder 1 oder n Benutzen
-- ============================================
CREATE TABLE users_groups (
    user_id   INT NOT NULL,
    groups_id INT NOT NULL,
    PRIMARY KEY (user_id, groups_id), -- Benutzer-Gruppe-Kombination
    role      ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member',
    assignment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE, -- beim Löschen ein User -> mitgliedschaften löschen
    FOREIGN KEY (groups_id) REFERENCES groups(id) ON DELETE RESTRICT -- Gruppen mit Benutzern nicht löschen
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- PROJECTS  
-- ============================================
CREATE TABLE projects (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by  INT NOT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at DATETIME NULL, -- soft delete für das MVP.
    archived_by INT NULL,
    FOREIGN KEY (created_by)  REFERENCES users(id),
    FOREIGN KEY (archived_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- PROJECTS_GROUPS (n:m relationship)
-- ============================================
ALTER TABLE groups ADD CONSTRAINT fk_groups_project
    FOREIGN KEY (project_id) REFERENCES projects(id);

CREATE TABLE projects_groups (
    project_id INT NOT NULL,
    group_id INT NOT NULL,
    PRIMARY KEY (project_id, group_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- CONTAINERS
-- Archivierung durch erbung von Projekten.
-- ============================================
CREATE TABLE containers (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(100) NOT NULL,
    project_id INT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL, -- soft delete für das MVP. -- ermöglicht das entfernen von Containern.
    deleted_by INT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (deleted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- TASKS
-- Archivierung durch erbung von Projekten.
-- soft delete für UI,UX
-- ============================================
CREATE TABLE tasks (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    container_id INT NOT NULL, -- Tasks exsisteren nur in einem Container 
    created_by   INT NULL,
    title        VARCHAR(100) NOT NULL,
    description  TEXT NULL,
    status       ENUM('open', 'in_progress', 'done', 'timed_out') NOT NULL DEFAULT 'open',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deadline     DATETIME NULL,
    attachment VARCHAR(255) NULL, -- URL zu Datei
    deleted_at   DATETIME NULL, -- soft delete
    deleted_by   INT NULL,
    done_by      INT NULL, -- statistische Auswertung.
    INDEX idx_tasks_deleted_at (deleted_at),
    FOREIGN KEY (container_id) REFERENCES containers(id) ON DELETE RESTRICT, -- Container nicht löschbar wenn noch Tasks existieren
    FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_deleted_by
        FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (done_by)      REFERENCES users(id) ON DELETE SET NULL -- task darf nicht gelöscht bei löschen eines Users
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- TASK_ATTACHMENTS
-- ============================================
CREATE TABLE task_attachments (
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

-- ============================================
-- GROUPS_TASKS (n:m) an Task können nur Gruppen zugeordnet. einzelne Users werden über die Gruppen zugeordnet.
-- ============================================
CREATE TABLE groups_tasks ( 
    group_id INT NOT NULL,
    task_id   INT NOT NULL,
    PRIMARY KEY (group_id, task_id) , -- Verhindert doppelte Einträge für die gleiche Gruppe-Task-Kombination
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE, -- Gruppen-Zugehörigkeit zu Task Löschen.
    FOREIGN KEY (task_id)   REFERENCES tasks(id) ON DELETE CASCADE -- Task Löschen -> Gruppen Zugehörigkeit Löschen
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- SUBTASKS
-- ============================================
CREATE TABLE subtasks (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    task_id   INT NOT NULL,
    title     VARCHAR(100) NOT NULL,
    completed BOOLEAN NOT NULL DEFAULT FALSE,
    created_by INT NULL,
    deleted_at DATETIME NULL,
    deleted_by INT NULL,
    INDEX idx_subtasks_task_deleted (task_id, deleted_at),
    FOREIGN KEY (task_id) REFERENCES tasks(id),
    CONSTRAINT fk_subtasks_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_subtasks_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    task_id    INT NOT NULL,
    user_id    INT NOT NULL,
    content    TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL, -- soft delete

    FOREIGN KEY (task_id) REFERENCES tasks(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);