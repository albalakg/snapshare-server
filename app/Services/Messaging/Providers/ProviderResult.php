<?php

namespace App\Services\Messaging\Providers;

final class ProviderResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $id = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function ok(string $id): self
    {
        return new self(true, $id);
    }

    public static function fail(?string $code, string $error): self
    {
        return new self(false, null, $code, mb_substr($error, 0, 250));
    }

    /** Meta 131026: recipient cannot be reached on WhatsApp (not a WhatsApp user / old app). */
    public function notOnWhatsApp(): bool
    {
        return !$this->ok && $this->errorCode === '131026';
    }
}
