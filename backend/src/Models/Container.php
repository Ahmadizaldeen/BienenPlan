<?php

namespace BienenPlan\Models;

use PDO;
use BienenPlan\Services\AccessService;

class Container {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // Projektmitglieder sehen alle Container, Aufgaben bleiben separat gefiltert.
    public function getAll(int $userId, ?int $projectId = null): array {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT c.*, p.archived_at AS project_archived_at
             FROM containers c
             JOIN projects p ON p.id = c.project_id
             ' . AccessService::ACTOR_JOIN . '
             WHERE c.deleted_at IS NULL
               AND ' . ($projectId === null ? 'p.archived_at IS NULL' :
                    AccessService::PROJECT_READ_STATE_SQL . ' AND p.id = :project_id') . '
               AND ' . AccessService::PROJECT_VIEW_SQL . '
             ORDER BY c.id DESC'
        );
        $parameters = ['access_user' => $userId];
        if ($projectId !== null) $parameters['project_id'] = $projectId;
        $stmt->execute($parameters);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Einzelnen Container per ID abrufen
    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM containers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $container = $stmt->fetch(PDO::FETCH_ASSOC);
        return $container ?: null;
    }

    // Projektmitglieder sehen Container auch dann, wenn sie nicht deren Ersteller sind.
    public function isVisibleToUser(int $containerId, int $userId): bool {
        return (new AccessService($this->pdo))->canViewContainer($userId, $containerId);
    }

    public function canManage(int $containerId, int $userId): bool {
        return (new AccessService($this->pdo))->canManageContainer($userId, $containerId);
    }

    public function canCreateInProject(int $projectId, int $userId): bool {
        return (new AccessService($this->pdo))->canCreateInProject($userId, $projectId);
    }

    // Neuen Container erstellen
    public function create(array $data): int {
        $stmt = $this->pdo->prepare('INSERT INTO containers (title, project_id, created_by)
            SELECT :title, p.id, a.id FROM projects p ' . AccessService::ACTOR_JOIN . '
            WHERE p.id = :project_id AND p.archived_at IS NULL AND ' . AccessService::PROJECT_VIEW_SQL);
        $stmt->execute([
            'title' => $data['title'],
            'project_id' => $data['project_id'],
            'access_user' => $data['created_by'],
        ]);
        if ($stmt->rowCount() !== 1) throw new \DomainException('Projekt nicht gefunden');
        return (int) $this->pdo->lastInsertId();
    }

    // Container aktualisieren
    public function update(int $id, string $title, int $userId): bool {
        $stmt = $this->pdo->prepare('UPDATE containers c JOIN projects p ON p.id = c.project_id
            ' . AccessService::ACTOR_JOIN . '
            SET c.title = :title WHERE c.id = :id AND c.deleted_at IS NULL AND p.archived_at IS NULL
              AND ' . AccessService::CONTAINER_MANAGE_SQL);
        $stmt->execute([
            'id' => $id,
            'access_user' => $userId,
            'title' => $title
        ]);
        return $stmt->rowCount() > 0 || $this->canManage($id, $userId);
    }

    // Container löschen
    public function delete(int $id, int $userId): bool {
        $this->pdo->beginTransaction();
        try {
            // Task creation/moves lock this row too, so the empty-container check stays valid.
            $lock = $this->pdo->prepare('SELECT id FROM containers WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            if (!(new AccessService($this->pdo))->canManageContainer($userId, $id)) {
                $this->pdo->rollBack();
                return false;
            }
            $tasks = $this->pdo->prepare('SELECT 1 FROM tasks WHERE container_id = :id AND deleted_at IS NULL LIMIT 1');
            $tasks->execute(['id' => $id]);
            if ($tasks->fetchColumn()) {
                throw new \DomainException('Container enthaelt aktive Tasks');
            }
            $stmt = $this->pdo->prepare('UPDATE containers c JOIN projects p ON p.id = c.project_id
                ' . AccessService::ACTOR_JOIN . '
                SET c.deleted_at = CURRENT_TIMESTAMP, c.deleted_by = :user_id
                WHERE c.id = :id AND c.deleted_at IS NULL AND p.archived_at IS NULL
                  AND ' . AccessService::CONTAINER_MANAGE_SQL);
            $stmt->execute(['id' => $id, 'user_id' => $userId, 'access_user' => $userId]);
            $this->pdo->commit();
            return $stmt->rowCount() === 1;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }
}