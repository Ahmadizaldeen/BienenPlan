<?php
namespace BienenPlan\Error;

use Throwable;

class BootstrapErrorHandler
{
    public static function handle(Throwable $exception): never
    {
        // Technische Details nur ins Log , use Psr\Log\LoggerInterface;
        // Sichere Antwort an Client
        http_response_code(500);

        header('Content-Type: application/json');

        echo json_encode([
            'error' => 'Internal Server Error',
            'message' => 'Application could not be started.',
            'status' => 500
        ]);

        exit;
    }
}