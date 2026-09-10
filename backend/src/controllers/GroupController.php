<?php
// Controller for managing groups and their relationships with users and tasks
// Provides endpoints to create, read, update, and delete groups, as well as manage their relationships with users and tasks.

// Endpoints:

// getAllGroups
// createGroup 
// addUserToGroup 
// getGroupsForTask 
// getUsersInGroup 
// assignGroup 
// removeGroup 
// getUsersInGroup
// getGroupsForUser
// addUserToGroup
// assignGroup
// removeGroup
// getGroupsForUser

namespace BienenPlan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use BienenPlan\Models\Group;

class GroupController
{
    private Group $groupModel;

    public function __construct(Group $groupModel)
    {
        $this->groupModel = $groupModel;
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
            'groups' => $this->groupModel->getAllGroups()
        ]);
    }

    // POST create a new Group
    public function createGroup(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        $name = $data['name'] ?? null;

        if (!$name) {
            return $this->jsonResponse($response, ['error' => 'Name der Gruppe ist erforderlich'], 400);
        }

        $success = $this->groupModel->createGroup($name);
        if (!$success) {
            return $this->jsonResponse($response, ['error' => 'Gruppe konnte nicht erstellt werden'], 500);
        }

        return $this->jsonResponse($response, ['message' => 'Gruppe erfolgreich erstellt'], 201);
    }

    // GET all groups for a Task
    public function getGroupsForTask(Request $request, Response $response, array $args): Response
    {
        $taskId = $this->getRouteId($args, 'taskId');

        if ($taskId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Task-ID'], 400);
        }

        return $this->jsonResponse($response, [
            'groups' => $this->groupModel->getGroupsForTask($taskId) 
        ]);
    }

    public function assignGroup(Request $request, Response $response, array $args): Response # TODO Validierung der IDs, Assignment prüfen ob vorhanden
    {
        $taskId = $this->getRouteId($args, 'taskId');
        $groupId = $this->getRouteId($args, 'groupId');

        if ($taskId === null || $groupId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Task- oder Gruppen-ID'], 400);
        }

        $this->groupModel->assignGroup($taskId, $groupId);
        return $this->jsonResponse($response, ['message' => 'Gruppe dem Task zugeordnet'], 201);
    }

    public function removeGroup(Request $request, Response $response, array $args): Response
    {
        $taskId = $this->getRouteId($args, 'taskId');
        $groupId = $this->getRouteId($args, 'groupId');

        if ($taskId === null || $groupId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Task- oder Gruppen-ID'], 400);
        }

        $this->groupModel->removeGroup($taskId, $groupId);
        return $this->jsonResponse($response, ['message' => 'Gruppe vom Task entfernt']);
    }

    public function addUserToGroup(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getRouteId($args, 'userId');
        $groupId = $this->getRouteId($args, 'groupId');

        if ($userId === null || $groupId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige User- oder Gruppen-ID'], 400);
        }

        $this->groupModel->addUserToGroup($userId, $groupId);
        return $this->jsonResponse($response, ['message' => 'User der Gruppe hinzugefügt'], 201);
    }

    // GET all users for a specific group
    public function getUsersInGroup(Request $request, Response $response, array $args): Response # TODO Personal Groups
    {
        $groupId = $this->getRouteId($args, 'groupId');

        if ($groupId === null) {
            return $this->jsonResponse($response, ['error' => 'Ungültige Gruppen-ID'], 400);
        }

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
            'groups' => $this->groupModel->getGroupsForUser($userId)
        ]);
    }

}
