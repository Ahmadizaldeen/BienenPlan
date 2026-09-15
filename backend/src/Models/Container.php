<?php

namespace BienenPlan\Models;

use PDO;

class Container {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // Alle Container eines Benutzers abrufen
    public function getAll(int $userId): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM containers WHERE created_by = :user_id AND deleted_at IS NULL ORDER BY id DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Einzelnen Container per ID abrufen
    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM containers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $container = $stmt->fetch(PDO::FETCH_ASSOC);
        return $container ?: null;
    }

    // Neuen Container erstellen
    public function create(array $data): int {
        $stmt = $this->pdo->prepare('INSERT INTO containers (title, project_id, created_by) VALUES (:title, :project_id, :created_by)');
        $stmt->execute([
            'title' => $data['title'],
            'project_id' => $data['project_id'],
            'created_by' => $data['created_by'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    // Container aktualisieren
    public function update(int $id, string $title): bool {
        $stmt = $this->pdo->prepare('UPDATE containers SET title = :title WHERE id = :id');
        return $stmt->execute([
            'id' => $id,
            'title' => $title
        ]);
    }

    // Container löschen
    public function delete(int $id): bool {
        $stmt = $this->pdo->prepare('DELETE FROM containers WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}