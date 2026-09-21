<?php

namespace BienenPlan\Models;

use PDO;

class Container {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // Alle Container eines Benutzers abrufen.
    // Sichtbar sind eigene Container sowie Container mit einer Aufgabe, die
    // einer Gruppe des Benutzers zugewiesen ist (z.B. seiner persönlichen
    // Gruppe "Personal user {id}").
    public function getAll(int $userId): array {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT c.*
             FROM containers c
             WHERE c.deleted_at IS NULL
               AND (
                 c.created_by = :user_id
                 OR EXISTS (
                     SELECT 1
                     FROM tasks t
                     JOIN groups_tasks gt ON gt.task_id = t.id
                     JOIN users_groups ug ON ug.groups_id = gt.group_id
                     WHERE t.container_id = c.id
                       AND t.deleted_at IS NULL
                       AND ug.user_id = :shared_user_id
                 )
               )
             ORDER BY c.id DESC'
        );
        $stmt->execute(['user_id' => $userId, 'shared_user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Einzelnen Container per ID abrufen
    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM containers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $container = $stmt->fetch(PDO::FETCH_ASSOC);
        return $container ?: null;
    }

    // Prüft, ob ein Benutzer einen Container sehen/bearbeiten darf: entweder
    // als Ersteller, oder weil ihm eine Aufgabe im Container über eine seiner
    // Gruppen zugewiesen wurde.
    public function isVisibleToUser(int $containerId, int $userId): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM containers c
             WHERE c.id = :container_id
               AND (
                 c.created_by = :user_id
                 OR EXISTS (
                     SELECT 1
                     FROM tasks t
                     JOIN groups_tasks gt ON gt.task_id = t.id
                     JOIN users_groups ug ON ug.groups_id = gt.group_id
                     WHERE t.container_id = c.id
                       AND t.deleted_at IS NULL
                       AND ug.user_id = :shared_user_id
                 )
               )
             LIMIT 1'
        );
        $stmt->execute([
            'container_id' => $containerId,
            'user_id' => $userId,
            'shared_user_id' => $userId,
        ]);
        return (bool) $stmt->fetchColumn();
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