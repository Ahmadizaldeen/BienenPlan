<?php

namespace BienenPlan\Controllers;

use BienenPlan\Models\User;
use BienenPlan\Services\FileValidationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Verarbeitet das Profilbild des aktuell authentifizierten Benutzers. */
class UserController
{
    // Klein genug für Profilbilder und identisch zum Limit im Frontend.
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;
    private FileValidationService $fileValidationService;

    public function __construct(
        private User $userModel,
        ?FileValidationService $fileValidationService = null,
    ) {
        $this->fileValidationService = $fileValidationService ?? new FileValidationService();
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    // POST /api/me/picture (multipart/form-data, Feld: "file")
    public function uploadPicture(Request $request, Response $response): Response
    {
        // Die Benutzer-ID kommt ausschließlich aus dem geprüften JWT und kann
        // deshalb nicht über Formulardaten manipuliert werden.
        $userId = (int) $request->getAttribute('user_id');
        $user = $this->userModel->findById($userId);
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Benutzer nicht gefunden'], 404);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;
        if (!$file) {
            return $this->jsonResponse($response, ['error' => 'Kein gültiges Bild hochgeladen'], 400);
        }

        try {
            $validation = $this->fileValidationService->validate(
                $file,
                [
                    'jpg' => ['image/jpeg'],
                    'jpeg' => ['image/jpeg'],
                    'png' => ['image/png'],
                ],
                self::MAX_FILE_SIZE,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonResponse($response, ['error' => $exception->getMessage()], 400);
        }

        $detectedMime = $validation['mimeType'];
        $extension = $validation['extension'];

        $uploadDir = __DIR__ . '/../../public/uploads/users';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return $this->jsonResponse($response, ['error' => 'Upload-Verzeichnis konnte nicht erstellt werden'], 500);
        }

        // Dateiname und Endung werden ausschließlich aus serverseitig
        // ermittelten Werten aufgebaut; der Originalname wird nicht gespeichert.
        $safeExtension = $detectedMime === 'image/jpeg' ? 'jpg' : 'png';
        $safeName = sprintf('%d_%s.%s', $userId, bin2hex(random_bytes(8)), $safeExtension);
        $file->moveTo($uploadDir . '/' . $safeName);

        $relativePath = 'uploads/users/' . $safeName;
        if (!$this->userModel->setPicture($userId, $relativePath)) {
            @unlink($uploadDir . '/' . $safeName);
            return $this->jsonResponse($response, ['error' => 'Profilbild konnte nicht gespeichert werden'], 500);
        }

        // Das alte Bild wird erst nach erfolgreichem DB-Update entfernt, damit
        // bei einem Speicherfehler weiterhin ein gültiges Profilbild existiert.
        $oldPicture = $user['picture'] ?? null;
        if (is_string($oldPicture) && str_starts_with($oldPicture, 'uploads/users/')) {
            $oldPath = __DIR__ . '/../../public/' . $oldPicture;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $updatedUser = $this->userModel->findById($userId);
        return $this->jsonResponse($response, [
            'message' => 'Profilbild erfolgreich aktualisiert',
            'user' => $updatedUser,
        ]);
    }
}
