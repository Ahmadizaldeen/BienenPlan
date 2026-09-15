<?php

namespace BienenPlan\Models;

use PDO;

class Project {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // CREATE
    public function create(array $data): int {
        $sql = "INSERT INTO projects (name, created_by) 
                VALUES (:name, :created_by)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'name'        => $data['name'],
            'created_by'  => $data['created_by']
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // READ (Alle nicht-archivierten projects)
    public function getAll(int $userId): array {

        $sql = "SELECT p.*, u.name AS creator_name 
                FROM projects p
                JOIN users u ON p.created_by = u.id
                WHERE p.archived_at IS NULL AND p.created_by = :user_id
                ORDER BY p.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // READ (Einzelnes Project nach ID, nur wenn nicht archiviert)
    public function getById(int $id): ?array {
        $sql = "SELECT p.*, u.name AS creator_name 
                FROM projects p
                JOIN users u ON p.created_by = u.id
                WHERE p.id = :id AND p.archived_at IS NULL";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    // UPDATE
    public function update(int $id, array $data): bool {
        $sql = "UPDATE projects 
                SET name = :name
                WHERE id = :id AND archived_at IS NULL";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id'   => $id,
            'name' => $data['name']
        ]);
    }

    // DELETE (Soft-Delete)
    public function archive(int $id, int $archivedBy): bool {
        $sql = "UPDATE projects 
                SET archived_at = NOW(), archived_by = :archived_by 
                WHERE id = :id AND archived_at IS NULL";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id'         => $id,
            'archived_by' => $archivedBy
        ]);
    }
}