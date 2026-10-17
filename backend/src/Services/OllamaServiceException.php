<?php

namespace BienenPlan\Services;

use RuntimeException;

class OllamaServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
