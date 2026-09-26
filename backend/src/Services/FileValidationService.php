<?php

namespace BienenPlan\Services;

use Psr\Http\Message\UploadedFileInterface;

/** Validiert Uploads anhand von Dateiendung und tatsächlichem Dateiinhalt. */
class FileValidationService {
    /**
     * @param array<string, array<int, string>> $allowedMimeTypes
     * @return array{extension: string, mimeType: string}
     */
    public function validate(
        UploadedFileInterface $file,
        array $allowedMimeTypes,
        int $maxFileSize,
    ): array {
        // Der MIME-Typ wird aus den empfangenen Bytes ermittelt, nicht aus dem
        // vom Client gesetzten Content-Type-Header.
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Keine gültige Datei hochgeladen');
        }

        // Der Dateiname liefert nur die zu prüfende Endung und wird nie als
        // Speichername übernommen.
        $filename = $file->getClientFilename() ?? '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!isset($allowedMimeTypes[$extension])) {
            throw new \InvalidArgumentException('Dateityp nicht erlaubt');
        }
        //
        $stream = $file->getStream(); // Holt den Stream der hochgeladenen Datei, um den Inhalt zu prüfen.
        $stream->rewind(); // Setzt den Stream auf den Anfang zurück, um den gesamten Inhalt zu lesen.
        $contents = $stream->getContents(); // Liest den gesamten Inhalt der Datei in einen String.
        $stream->rewind(); // Setzt den Stream erneut auf den Anfang zurück, falls später noch darauf zugegriffen wird.

        if ($contents === '') {
            throw new \InvalidArgumentException('Die Datei ist leer');
        }
        if (strlen($contents) > $maxFileSize) {
            throw new \InvalidArgumentException('Datei ist zu groß (max. 10 MB)');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (!in_array($mimeType, $allowedMimeTypes[$extension], true)) {
            throw new \InvalidArgumentException(
                'Dateiendung stimmt nicht mit dem MIME-Typ überein'
            );
        }

        // MIME-Abgleich allein reicht nicht für jedes Format: PDF, TXT und
        // Office-Dateien benötigen zusätzlich eine Format-Signaturprüfung.
        $this->validateBinaryContent($extension, $contents, $mimeType);

        return ['extension' => $extension, 'mimeType' => $mimeType];
    }

    private function validateBinaryContent(
        string $extension,
        string $contents,
        string $mimeType,
    ): void {
        if ($extension === 'pdf' && !str_starts_with($contents, '%PDF-')) {
            throw new \InvalidArgumentException('Die Datei ist kein gültiges PDF');
        }

        if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true)) {
            if (@getimagesizefromstring($contents) === false) {
                throw new \InvalidArgumentException('Die Bilddatei ist ungültig');
            }
        }

        if ($extension === 'txt') {
            if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
                throw new \InvalidArgumentException('TXT-Datei enthält binäre oder ungültige Daten');
            }
        }

        if (in_array($extension, ['docx', 'xlsx'], true) && !str_starts_with($contents, "PK")) {
            throw new \InvalidArgumentException('Office-Datei ist binär ungültig');
        }
    }
}