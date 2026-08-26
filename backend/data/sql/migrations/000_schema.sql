-- DROP DATABASE IF EXISTS `bienenplan`; -- FÜR ENTWICKLUNGSPHASE
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
    FOREIGN KEY (user_id)   REFERENCES users(id),
    FOREIGN KEY (groups_id) REFERENCES groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;