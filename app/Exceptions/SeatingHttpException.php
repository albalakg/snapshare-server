<?php

namespace App\Exceptions;

use Exception;

/**
 * Domain error for guest CRM / seating endpoints that carries an HTTP status
 * (403 entitlement, 404 not found, 409 revision conflict, 422 validation, 429 quota).
 */
class SeatingHttpException extends Exception
{
    public function __construct(string $message, private int $status = 400, private array $payload = [])
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
