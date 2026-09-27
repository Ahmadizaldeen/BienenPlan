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

        if ($file->getSize() !== null && $file->getSize() > $maxFileSize) {
            throw new \InvalidArgumentException($this->fileTooLargeMessage($maxFileSize));
        }

        $stream = $file->getStream();
        $stream->rewind();
        $contents = '';
        while (!$stream->eof()) {
            $bytesRemaining = $maxFileSize + 1 - strlen($contents);
            if ($bytesRemaining <= 0) {
                throw new \InvalidArgumentException($this->fileTooLargeMessage($maxFileSize));
            }

            $chunk = $stream->read(min(8192, $bytesRemaining));
            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }
                throw new \RuntimeException('Hochgeladene Datei konnte nicht vollständig gelesen werden');
            }

            $contents .= $chunk;
            if (strlen($contents) > $maxFileSize) {
                throw new \InvalidArgumentException($this->fileTooLargeMessage($maxFileSize));
            }
        }
        $stream->rewind();

        if ($contents === '') {
            throw new \InvalidArgumentException('Die Datei ist leer');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (!in_array($mimeType, $allowedMimeTypes[$extension], true)) {
            throw new \InvalidArgumentException(
                'Dateiendung stimmt nicht mit dem MIME-Typ überein'
            );
        }

        // MIME-Abgleich allein reicht nicht für jedes Format.
        $this->validateBinaryContent($extension, $contents, $mimeType);

        return ['extension' => $extension, 'mimeType' => $mimeType];
    }

    private function fileTooLargeMessage(int $maxFileSize): string
    {
        if ($maxFileSize % (1024 * 1024) === 0) {
            $maxSize = sprintf('%d MB', intdiv($maxFileSize, 1024 * 1024));
        } elseif ($maxFileSize % 1024 === 0) {
            $maxSize = sprintf('%d KB', intdiv($maxFileSize, 1024));
        } else {
            $maxSize = sprintf('%d Bytes', $maxFileSize);
        }

        return sprintf('Datei ist zu groß (max. %s)', $maxSize);
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

        if (in_array($extension, ['docx', 'xlsx'], true)) {
            $this->validateOfficePackage($extension, $contents);
        }
    }

    private function validateOfficePackage(string $extension, string $contents): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Die PHP-ZIP-Erweiterung ist nicht verfügbar');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'bienenplan-upload-');
        if ($tempFile === false) {
            throw new \RuntimeException('Temporäre Datei für die Office-Prüfung konnte nicht erstellt werden');
        }

        try {
            if (file_put_contents($tempFile, $contents) !== strlen($contents)) {
                throw new \RuntimeException('Office-Datei konnte nicht zur Prüfung geschrieben werden');
            }

            $archive = new \ZipArchive();
            if ($archive->open($tempFile) !== true) {
                throw new \InvalidArgumentException('Office-Datei ist kein gültiges ZIP-Archiv');
            }

            try {
                $documentPath = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
                $mainContentType = $extension === 'docx'
                    ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
                $contentTypesIndex = $archive->locateName('[Content_Types].xml');
                $contentTypesStats = $contentTypesIndex === false
                    ? false
                    : $archive->statIndex($contentTypesIndex);
                $contentTypes = $contentTypesStats !== false && $contentTypesStats['size'] <= 1024 * 1024
                    ? $archive->getFromIndex($contentTypesIndex)
                    : false;
                $isValidPackage = $archive->locateName('_rels/.rels') !== false
                    && $archive->locateName($documentPath) !== false
                    && is_string($contentTypes)
                    && $this->hasOfficeMainContentType($contentTypes, $documentPath, $mainContentType);
            } finally {
                $archive->close();
            }

            if (!$isValidPackage) {
                throw new \InvalidArgumentException('Office-Datei enthält kein gültiges DOCX- oder XLSX-Paket');
            }
        } finally {
            unlink($tempFile);
        }
    }

    private function hasOfficeMainContentType(
        string $contentTypes,
        string $documentPath,
        string $mainContentType,
    ): bool {
        $document = new \DOMDocument();
        if (!$document->loadXML($contentTypes, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return false;
        }

        $overrides = $document->getElementsByTagNameNS(
            'http://schemas.openxmlformats.org/package/2006/content-types',
            'Override',
        );
        foreach ($overrides as $override) {
            if (
                $override->getAttribute('PartName') === '/' . $documentPath
                && $override->getAttribute('ContentType') === $mainContentType
            ) {
                return true;
            }
        }

        return false;
    }
}