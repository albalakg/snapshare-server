<?php

namespace App\Exceptions;

use Exception;

class OpenWaApiException extends Exception
{
    public function __construct(
        string $message,
        private int $statusCode = 0,
        private string $responseBody = '',
        private ?string $errorCode = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
