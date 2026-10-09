<?php
// Controller for managing groups and their relationships with users and tasks
// Provides endpoints to create, read, update, and delete groups, as well as manage their relationships with users and tasks.

// Endpoints:
// getAllGroups
// createGroup
// addUserToGroup
// getGroupsForTask
// assignGroup
// removeGroup
// getUsersInGroup
// getGroupsForUser
// getPersonalGroupUser

namespace BienenPlan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use BienenPlan\Models\Group;
use BienenPlan\Models\User;

class GroupController
{
    private Group $groupModel;
    private User $userModel;

    // User-Model wird injiziert, damit Benutzerdaten nur über User::findById geladen werden.
    public function __construct(Group $groupModel, User $userModel)
    {
        $this->groupModel = $groupModel;
        $this->userModel = $userModel;
    }

    // Helper für JSON-Antworten
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function getRouteId(array $args, string $key): ?int
    {
        // Holt die IDs aus den Routen-Parametern, key in $args angegeben bei der Route
    
        $value = $args[$key] ?? null;

        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }
    //GET all groups
    public function getAllGroups(Request $request, Response $response, array $args): Response
    {
        return $this->jsonResponse($response, [
            'groups' => $this->groupModel->getAllGroups((int) $request->getAttribute('user_id'))
        ]);
    }

    // POST create a new Group
    public function createGroup(Request $request, Response $response, array $args): Response
    {
        if (!$this->groupModel->access()->isAdmin((int) $request->getAttribute('user_id'))) {
            return $this->jsonResponse($response, ['error' => 'Nur Admin darf globale Gruppen erstellen'], 403);
        }
        $data = $request->getParsedBody();
        if (!is_array($data) || !Group::isValidName($data['name'] ?? null)) {
            return $this->jsonResponse($response, ['error' => 'Gruppenname muss 1 bis 100 Zeichen enthalten'], 400);
        }
        $name = trim($data['name']);
        $rawUserIds = $data['user_ids'] ?? [];

        // "Personal user ..." ist für persönliche Gruppen reserviert (verhindert vorgetäuschte Zuordnungen).
        if (Group::isReservedName($name)) {
            return $this->jsonResponse($response, ['error' => 'Dieser Gruppenname ist reserviert'], 400);
        }

        if (!is_array($rawUserIds)) {
            return $this->jsonResponse($response, ['error' => 'user_ids muss eine Liste sein'], 400);
        }

        $userIds = [];
        foreach ($rawUserIds as $userId) {
            if ((!is_int($userId) && (!is_string($userId) || !ctype_digit($userId))) || (int) $userId < 1) {
                return $this->jsonResponse($response, ['error' => 'Ungültige Benutzer-ID'], 400);
            }
            $userIds[] = (int) $userId;
        }
        $userIds = array_values(array_unique($userIds));

        try {
            $groupId = $this->groupModel->createGroup($name, $userIds, (int) $request->getAttribute('user_id'));
        } catch (\DomainException $exception) {
            return $this->jsonResponse($response, ['error' => $exception->getMessage()], 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonResponse($response, ['error' => $exception->getMessage()], 400);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062) {
                return $this->jsonResponse($response, ['error' => 'Eine Gruppe mit diesem Namen existiert bereits'], 409);
            }

            throw $exception;
        }
        if ($groupId < 1) {
            return $this->jsonResponse($response, ['error' => 'Gruppe konnte nicht erstellt werden'], 500);
        }

        return $this->jsonResponse($response, [
            'id' => $groupId,
            'name' => $name,
            'message' => 'Gruppe erfolgreich erstellt'
        ], 201);
    }

    // GET all groups for a Task
    public function getGroupsForTask(Request $request, Response $response, array $args): Response
    {
        $taskId = $this->getRouteId($args, 'taskId');

        if ($taskId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Task-ID'], 400);
        }
        if (!$this->groupModel->access()->canViewTask((int) $request->getAttribute('user_id'), $taskId)) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, [
            'groups' => $this->groupModel->getGroupsForTask($taskId) 
        ]);
    }

    public function assignGroup(Request $request, Response $response, array $args): Response
    {
        $taskId = $this->getRouteId($args, 'taskId');
        $groupId = $this->getRouteId($args, 'groupId');

        if ($taskId === null || $groupId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Task- oder Gruppen-ID'], 400);
        }
        if ($denied = $this->taskGroupPermission($request, $response, $taskId)) return $denied;

        try {
            $assigned = $this->groupModel->assignGroup($taskId, $groupId, (int) $request->getAttribute('user_id'));
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062) {
                return $this->jsonResponse($response, ['error' => 'Gruppe ist diesem Task bereits zugeordnet'], 409);
            }

            throw $exception;
        }

        if (!$assigned) {
            return $this->jsonResponse($response, ['error' => 'Task oder Gruppe nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, ['message' => 'Gruppe dem Task zugeordnet'], 201);
    }

    public function removeGroup(Request $request, Response $response, array $args): Response
    {
        $taskId = $this->getRouteId($args, 'taskId');
        $groupId = $this->getRouteId($args, 'groupId');

        if ($taskId === null || $groupId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Task- oder Gruppen-ID'], 400);
        }
        if ($denied = $this->taskGroupPermission($request, $response, $taskId)) return $denied;

        if (!$this->groupModel->removeGroup($taskId, $groupId, (int) $request->getAttribute('user_id'))) {
            return $this->jsonResponse($response, ['error' => 'Task oder Gruppenzuweisung nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, ['message' => 'Gruppe vom Task entfernt']);
    }

    public function addUserToGroup(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getRouteId($args, 'userId');
        $groupId = $this->getRouteId($args, 'groupId');

        if ($userId === null || $groupId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige User- oder Gruppen-ID'], 400);
        }

        if ($denied = $this->groupPermission($request, $response, $groupId, true)) return $denied;
        try {
            if (!$this->groupModel->addUserToGroup($userId, $groupId, (int) $request->getAttribute('user_id'))) {
                return $this->jsonResponse($response, ['error' => 'Benutzer nicht gefunden'], 404);
            }
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return $this->jsonResponse($response, ['error' => 'Mitgliedschaft besteht bereits'], 409);
            }
            throw $exception;
        }
        return $this->jsonResponse($response, ['message' => 'User der Gruppe hinzugefügt'], 201);
    }

    // GET all users for a specific group
    public function getUsersInGroup(Request $request, Response $response, array $args): Response # TODO Personal Groups
    {
        $groupId = $this->getRouteId($args, 'groupId');

        if ($groupId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Gruppen-ID'], 400);
        }
        if ($denied = $this->groupPermission($request, $response, $groupId)) return $denied;

        return $this->jsonResponse($response, [
            'users' => $this->groupModel->getUsersInGroup($groupId)
        ]);
    }

    // GET all groups for a specific user
    public function getGroupsForUser(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getRouteId($args, 'userId');

        if ($userId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige User-ID'], 400);
        }

        return $this->jsonResponse($response, [
            'groups' => $this->groupModel->getGroupsForUser($userId, (int) $request->getAttribute('user_id'))
        ]);
    }

    // GET user data of a personal group ("Personal user {user_id}")
    public function getPersonalGroupUser(Request $request, Response $response, array $args): Response
    {
        $groupId = $this->getRouteId($args, 'groupId');

        if ($groupId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Gruppen-ID'], 400);
        }
        if ($denied = $this->groupPermission($request, $response, $groupId)) return $denied;

        $group = $this->groupModel->findGroupById($groupId);
        if ($group === false) {
            return $this->jsonResponse($response, ['error' => 'Gruppe nicht gefunden'], 404);
        }

        // Erkennung zentral im Model (FK-Spalte vor Namens-Fallback), nicht mehr doppelt hier.
        $userId = Group::resolvePersonalUserId($group);
        if ($userId === null) {
            return $this->jsonResponse($response, ['error' => 'Gruppe ist keine persönliche Gruppe'], 422);
        }

        $user = $this->userModel->findById($userId);
        if ($user === false) {
            return $this->jsonResponse($response, ['error' => 'Benutzer der persönlichen Gruppe nicht gefunden'], 404);
        }

        return $this->jsonResponse($response, [
            'group_id' => (int) $group['id'],
            'user' => $user
        ]);
    }

    private function taskGroupPermission(Request $request, Response $response, int $taskId): ?Response
    {
        $access = $this->groupModel->access();
        $userId = (int) $request->getAttribute('user_id');
        if (!$access->canViewTask($userId, $taskId)) {
            return $this->jsonResponse($response, ['error' => 'Task nicht gefunden'], 404);
        }
        if (!$access->canManageTaskGroups($userId, $taskId)) {
            return $this->jsonResponse($response, ['error' => 'Keine Berechtigung'], 403);
        }
        return null;
    }

    private function groupPermission(Request $request, Response $response, int $groupId, bool $manage = false): ?Response
    {
        $access = $this->groupModel->access();
        $userId = (int) $request->getAttribute('user_id');
        if (!$access->canViewGroup($userId, $groupId)) {
            return $this->jsonResponse($response, ['error' => 'Gruppe nicht gefunden'], 404);
        }
        if ($manage && !$access->canManageGroup($userId, $groupId)) {
            return $this->jsonResponse($response, ['error' => 'Keine Berechtigung'], 403);
        }
        return null;
    }

    public function removeUserFromGroup(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getRouteId($args, 'userId');
        $groupId = $this->getRouteId($args, 'groupId');
        if ($userId === null || $groupId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungueltige User- oder Gruppen-ID'], 400);
        }
        if ($denied = $this->groupPermission($request, $response, $groupId, true)) return $denied;
        if (!$this->groupModel->removeUserFromGroup($userId, $groupId, (int) $request->getAttribute('user_id'))) {
            return $this->jsonResponse($response, ['error' => 'Mitgliedschaft nicht gefunden'], 404);
        }
        return $this->jsonResponse($response, ['message' => 'Mitgliedschaft entfernt']);
    }
}
