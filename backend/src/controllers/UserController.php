<?php

namespace BienenPlan\Controllers;

use BienenPlan\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Verarbeitet das Profilbild des aktuell authentifizierten Benutzers. */
class UserController {
    // Klein genug für Profilbilder und identisch zum Limit im Frontend.
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public function __construct(private User $userModel) {
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    // POST /api/me/picture (multipart/form-data, Feld: "file")
    public function uploadPicture(Request $request, Response $response): Response {
        // Die Benutzer-ID kommt ausschließlich aus dem geprüften JWT und kann
        // deshalb nicht über Formulardaten manipuliert werden.
        $userId = (int) $request->getAttribute('user_id');
        $user = $this->userModel->findById($userId);
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Benutzer nicht gefunden'], 404);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonResponse($response, ['error' => 'Kein gültiges Bild hochgeladen'], 400);
        }

        // Der Client-Dateiname ist nicht vertrauenswürdig. Seine Endung wird
        // nur als zusätzliche Bedingung geprüft, nie als Inhaltsnachweis.
        $originalName = $file->getClientFilename() ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return $this->jsonResponse($response, ['error' => 'Nur JPG- und PNG-Bilder sind erlaubt'], 400);
        }

        $stream = $file->getStream();
        $stream->rewind();
        $contents = $stream->getContents();
        $stream->rewind();

        if (strlen($contents) > self::MAX_FILE_SIZE) {
            return $this->jsonResponse($response, ['error' => 'Bild ist zu groß (max. 5 MB)'], 400);
        }

        // Zwei unabhängige Prüfungen bestätigen Bildstruktur und MIME-Typ aus
        // dem Binärinhalt statt aus dem vom Client gesendeten Content-Type.
        $imageInfo = @getimagesizefromstring($contents);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($contents);
        $allowedTypes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
        ];

        if ($imageInfo === false || !isset($allowedTypes[$detectedMime])) {
            return $this->jsonResponse($response, ['error' => 'Dateiinhalt ist kein gültiges JPG- oder PNG-Bild'], 400);
        }

        // Verhindert beispielsweise eine PNG-Datei, die in .jpg umbenannt wurde.
        if (!in_array($extension, $allowedTypes[$detectedMime], true)) {
            return $this->jsonResponse($response, ['error' => 'Dateiendung stimmt nicht mit dem Bildformat überein'], 400);
        }

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