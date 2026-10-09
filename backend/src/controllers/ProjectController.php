<?php

namespace BienenPlan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use BienenPlan\Models\Project;
use BienenPlan\Models\Group;

class ProjectController {
    private Project $projectModel;

    public function __construct(Project $projectModel) {
        $this->projectModel = $projectModel;
    }

    // Helper für JSON-Antworten
    private function jsonResponse(Response $response, array $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }


        // POST /api/project
    public function create(Request $request, Response $response): Response {
            $data = $request->getParsedBody();
        $userId = $request->getAttribute('user_id');

            if (!is_array($data) || empty(trim((string) ($data['name'] ?? '')))) {
                return $this->jsonResponse($response, ['error' => 'name ist erforderlich'], 400);
            }

        $data['created_by'] = $userId;

        try {
            $projectId = $this->projectModel->create($data);
            return $this->jsonResponse($response, [
                'message' => 'Projekt erfolgreich erstellt',
                'id' => $projectId
            ], 201);
        } catch (\DomainException $exception) {
            return $this->jsonResponse($response, ['error' => $exception->getMessage()], 401);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && ($e->errorInfo[1] ?? null) === 1062) {
                return $this->jsonResponse($response, ['error' => 'Projektname bereits vergeben'], 409);
            }
            throw $e;
        }
    }

    // GET /api/projects
    public function getAll(Request $request, Response $response): Response {
        $userId = $request->getAttribute('user_id');
        $projekts = $this->projectModel->getAll($userId);
        return $this->jsonResponse($response, $projekts);
    }

    // GET /api/projects/{id}
    public function getById(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        $project = $this->projectModel->getById($id, $userId);

        if (!$project || !$this->projectModel->isVisibleToUser($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Projekt nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, $project);
    }

    public function getArchived(Request $request, Response $response): Response {
        $userId = (int) $request->getAttribute('user_id');
        if (!$this->projectModel->isAdmin($userId)) {
            return $this->jsonResponse($response, ['error' => 'Nur Admin darf das Projektarchiv ansehen'], 403);
        }
        return $this->jsonResponse($response, $this->projectModel->getAll($userId, true));
    }

    public function restore(Request $request, Response $response, array $args): Response {
        $userId = (int) $request->getAttribute('user_id');
        if (!$this->projectModel->isAdmin($userId)) {
            return $this->jsonResponse($response, ['error' => 'Nur Admin darf Projekte wiederherstellen'], 403);
        }
        $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return $this->jsonResponse($response, ['error' => 'Ungueltige Projekt-ID'], 400);
        }
        if (!$this->projectModel->getById($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Projekt nicht gefunden'], 404);
        }
        if (!$this->projectModel->restore($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Projekt ist nicht archiviert oder Berechtigung entzogen'], 409);
        }
        return $this->jsonResponse($response, ['message' => 'Projekt wiederhergestellt',
            'project' => $this->projectModel->getById($id, $userId)]);
    }

    public function getGroups(Request $request, Response $response, array $args): Response {
        $projectId = (int) ($args['id'] ?? 0);
        $userId = (int) $request->getAttribute('user_id');
        if ($projectId < 1 || !$this->projectModel->isVisibleToUser($projectId, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Projekt nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, ['groups' => $this->projectModel->getGroups($projectId)]);
    }

    public function addGroup(Request $request, Response $response, array $args): Response {
        $projectId = (int) ($args['id'] ?? 0);
        $groupId = (int) ($args['groupId'] ?? 0);
        $userId = (int) $request->getAttribute('user_id');
        if ($projectId < 1 || $groupId < 1) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Projekt- oder Gruppen-ID'], 400);
        }
        if (!$this->projectModel->isOwner($projectId, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Keine Berechtigung'], 403);
        }

        try {
            if (!$this->projectModel->addGroup($projectId, $groupId, $userId)) {
                return $this->jsonResponse($response, ['error' => 'Projekt oder Gruppe nicht gefunden'], 404);
            }
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return $this->jsonResponse($response, ['error' => 'Gruppe ist dem Projekt bereits zugeordnet'], 409);
            }
            throw $exception;
        }

        return $this->jsonResponse($response, ['message' => 'Gruppe dem Projekt zugeordnet'], 201);
    }

    public function createGroup(Request $request, Response $response, array $args): Response {
        $projectId = (int) ($args['id'] ?? 0);
        $userId = (int) $request->getAttribute('user_id');
        $data = $request->getParsedBody();
        if (!is_array($data) || !Group::isValidName($data['name'] ?? null)) {
            return $this->jsonResponse($response, ['error' => 'Gruppenname muss 1 bis 100 Zeichen enthalten'], 400);
        }
        $name = trim($data['name']);
        $rawUserIds = $data['user_ids'] ?? [];

        if ($projectId < 1 || $name === '' || !is_array($rawUserIds) || $rawUserIds === []) {
            return $this->jsonResponse($response, ['error' => 'Name und mindestens ein Benutzer sind erforderlich'], 400);
        }
        if (!$this->projectModel->isOwner($projectId, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Keine Berechtigung'], 403);
        }
        if (Group::isReservedName($name)) {
            return $this->jsonResponse($response, ['error' => 'Dieser Gruppenname ist reserviert'], 400);
        }

        $userIds = [];
        foreach ($rawUserIds as $rawUserId) {
            if ((!is_int($rawUserId) && (!is_string($rawUserId) || !ctype_digit($rawUserId))) || (int) $rawUserId < 1) {
                return $this->jsonResponse($response, ['error' => 'Ungültige Benutzer-ID'], 400);
            }
            $userIds[] = (int) $rawUserId;
        }

        try {
            $groupId = $this->projectModel->createGroup($projectId, $name, array_values(array_unique($userIds)), $userId);
        } catch (\DomainException $exception) {
            return $this->jsonResponse($response, ['error' => $exception->getMessage()], 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonResponse($response, ['error' => $exception->getMessage()], 400);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return $this->jsonResponse($response, ['error' => 'Gruppe oder Benutzer nicht gefunden bzw. Name bereits vergeben'], 409);
            }
            throw $exception;
        }

        return $this->jsonResponse($response, ['id' => $groupId, 'name' => $name], 201);
    }

    public function removeGroup(Request $request, Response $response, array $args): Response {
        $projectId = (int) ($args['id'] ?? 0);
        $groupId = (int) ($args['groupId'] ?? 0);
        $userId = (int) $request->getAttribute('user_id');
        if ($projectId < 1 || $groupId < 1) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Projekt- oder Gruppen-ID'], 400);
        }
        if (!$this->projectModel->isOwner($projectId, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Keine Berechtigung'], 403);
        }
        if (!$this->projectModel->removeGroup($projectId, $groupId, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Projektzuordnung nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, ['message' => 'Gruppe vom Projekt entfernt']);
    }

        // DELETE /api/projects/{id}
    public function delete(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        if (!$this->projectModel->isOwner($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Keine Berechtigung'],
                $this->projectModel->isVisibleToUser($id, $userId) ? 403 : 404);
        }

        if (!$this->projectModel->archive($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Projekt oder Berechtigung inzwischen geaendert'], 409);
        }
        return $this->jsonResponse($response, ['message' => 'Projekt erfolgreich archiviert']);
    }

    // PUT /api/projects/{id}
    public function update(Request $request, Response $response, array $args): Response {
        $id = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        $data = $request->getParsedBody();

        if (!$this->projectModel->isOwner($id, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Keine Berechtigung'],
                $this->projectModel->isVisibleToUser($id, $userId) ? 403 : 404);
        }

        if (empty($data['name'])) {
            return $this->jsonResponse($response, ['error' => 'name darf nicht leer sein'], 400);
        }

        if (!$this->projectModel->update($id, $data, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Projekt oder Berechtigung inzwischen geaendert'], 409);
        }
        return $this->jsonResponse($response, ['message' => 'Projekt erfolgreich aktualisiert']);
    }


}