<?php

namespace BienenPlan\Models;

use PDO;

class Task {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // CREATE
    public function create(array $data): int {
        $sql = "INSERT INTO tasks (container_id, created_by, title, description, status, deadline, attachment) 
                VALUES (:container_id, :created_by, :title, :description, :status, :deadline, :attachment)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'container_id' => $data['container_id'],
            'created_by'   => $data['created_by'],
            'title'        => $data['title'],
            'description'  => $data['description'] ?? null,
            'status'       => $data['status'] ?? 'open',
            'deadline'     => $data['deadline'] ?? null,
            'attachment'   => $data['attachment'] ?? null
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // READ (Alle nicht-gelöschten Tasks aller Benutzer)
    public function getAll(): array {
        $sql = "SELECT t.*, c.title AS container_title, u.name AS creator_name 
                FROM tasks t
                JOIN containers c ON t.container_id = c.id
                JOIN users u ON t.created_by = u.id
                WHERE t.deleted_at IS NULL
                ORDER BY t.created_at DESC";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Tasks für einen User (eingeloggt) abrufen
    public function getAllByUser(int $userId): array { # TODO: Gruppenfilter , Gruppe mit Avatar für UI
    $sql = "SELECT 
                t.*, 
                c.title AS container_title, 
                p.id AS project_id,
                p.name AS project_name,
                u.name AS creator_name,
                GROUP_CONCAT(DISTINCT gt.group_id ORDER BY gt.group_id) AS group_ids,
                GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ') AS group_names
            FROM tasks t
            JOIN containers c ON t.container_id = c.id
            JOIN projects p ON c.project_id = p.id
            JOIN users u ON t.created_by = u.id
            JOIN groups_tasks gt ON gt.task_id = t.id
            JOIN groups g ON g.id = gt.group_id
            JOIN users_groups ug ON ug.groups_id = gt.group_id
            WHERE t.deleted_at IS NULL
              AND ug.user_id = :user_id
            GROUP BY 
                t.id, 
                c.title, 
                p.id,
                p.name,
                u.name
            ORDER BY t.created_at DESC";
    
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

    // READ (Einzelne Task nach ID)
    public function getById(int $id): ?array {
        $sql = "SELECT t.*, c.title AS container_title, u.name AS creator_name 
                FROM tasks t
                JOIN containers c ON t.container_id = c.id
                JOIN users u ON t.created_by = u.id
                WHERE t.id = :id AND t.deleted_at IS NULL";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    // UPDATE
    public function update(int $id, array $data): bool {
        $sql = "UPDATE tasks 
                SET title = :title, 
                    description = :description, 
                    status = :status, 
                    deadline = :deadline, 
                    attachment = :attachment 
                WHERE id = :id AND deleted_at IS NULL";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id'          => $id,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'status'      => $data['status'] ?? 'open',
            'deadline'    => $data['deadline'] ?? null,
            'attachment'  => $data['attachment'] ?? null
        ]);
    }

    // DELETE (Soft-Delete)
    public function delete(int $id, int $deletedBy): bool {
        $sql = "UPDATE tasks 
                SET deleted_at = NOW(), deleted_by = :deleted_by 
                WHERE id = :id AND deleted_at IS NULL";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id'         => $id,
            'deleted_by' => $deletedBy
        ]);
    }
}