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
    name       VARCHAR(255) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- USERS_GROUPS (n:m relationship)
-- Ein Benutzer angehört zu m Gruppen, Gruppen etweder 1 oder n Benutzen
-- ============================================
CREATE TABLE users_groups (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id   INT NOT NULL,
    groups_id INT NOT NULL,
    UNIQUE (user_id, groups_id), -- Verhindert doppelte Einträge für die gleiche Benutzer-Gruppe-Kombination
    FOREIGN KEY (user_id)   REFERENCES users(id),
    FOREIGN KEY (groups_id) REFERENCES groups(id)
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
    container_id INT NOT NULL,
    created_by   INT NOT NULL,
    title        VARCHAR(255) NOT NULL,
    description  TEXT NULL,
    status       ENUM('open', 'in_progress', 'done', 'timed_out') NOT NULL DEFAULT 'open',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deadline     DATETIME NULL,
    attachment VARCHAR(255) NULL, -- URL zu Datei
    deleted_at   DATETIME NULL, -- soft delete
    deleted_by   INT NULL,
    FOREIGN KEY (container_id) REFERENCES containers(id),
    FOREIGN KEY (created_by)   REFERENCES users(id),
    FOREIGN KEY (deleted_by)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- TASKS_GROUPS (n:m )
-- ============================================
CREATE TABLE tasks_groups (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    groups_id INT NOT NULL,
    task_id   INT NOT NULL,
    UNIQUE (groups_id, task_id), -- Verhindert doppelte Einträge für die gleiche Gruppe-Task-Kombination
    FOREIGN KEY (groups_id) REFERENCES groups(id),
    FOREIGN KEY (task_id)   REFERENCES tasks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- SUBTASKS
-- ============================================
CREATE TABLE subtasks (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    task_id   INT NOT NULL,
    title     VARCHAR(100) NOT NULL,
    completed BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY (task_id) REFERENCES tasks(id)
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