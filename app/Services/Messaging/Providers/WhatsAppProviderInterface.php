<?php

namespace App\Services\Messaging\Providers;

/**
 * Outbound WhatsApp provider (D4: Meta Cloud API directly; mock until WHATSAPP_* credentials exist).
 * Phone numbers are international digits without "+" (e.g. 972501234567).
 */
interface WhatsAppProviderInterface
{
    public function name(): string;

    /**
     * Send an approved template.
     *
     * @param array $template ['meta_name' => string, 'language' => 'he', 'header' => ?string, 'body' => string, 'buttons' => array]
     * @param array $params   named variables used by the template, e.g. ['first_name' => 'נועה']
     * @param array $buttonPayloads quick-reply payloads by button index
     */
    public function sendTemplate(string $to, array $template, array $params, array $buttonPayloads = []): ProviderResult;

    /** Free-form text (only inside the 24h customer service window). */
    public function sendText(string $to, string $body): ProviderResult;

    /** Interactive reply buttons (max 3, inside the 24h window). $buttons = [['id' => 'x', 'title' => '...']] */
    public function sendButtons(string $to, string $body, array $buttons): ProviderResult;

    /**
     * Submit a template for Meta review.
     *
     * @return array{id: ?string, status: string, reason: ?string}
     */
    public function submitTemplate(array $template): array;

    /**
     * @return array{status: string, reason: ?string}
     */
    public function templateStatus(array $template): array;
}
