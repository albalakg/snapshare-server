<?php

namespace App\Services\OpenWa;

use App\Services\Guests\PhoneNormalizer;
use RuntimeException;

class OpenWaService
{
    public function __construct(
        private OpenWaClient $client,
    ) {}

    public function getSessionStatus(): array
    {
        return $this->client->request('GET', '');
    }

    public function isConnected(): bool
    {
        try {
            $session = $this->getSessionStatus();

            return ($session['status'] ?? '') === 'ready'
                && !empty($session['phone']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function startSession(): array
    {
        return $this->client->request('POST', 'start');
    }

    public function getQr(): array
    {
        return $this->client->request('GET', 'qr');
    }

    public function checkContact(string $phone): array
    {
        $number = PhoneNormalizer::toWhatsApp($phone);

        return $this->client->request('GET', "contacts/check/{$number}");
    }

    public function registerWebhook(): array
    {
        return $this->client->request('POST', 'webhooks', [
            'url'    => config('openwa.webhook_url'),
            'events' => [
                'message.received',
                'message.sent',
                'message.ack',
                'session.status',
            ],
            'secret' => config('openwa.webhook_secret'),
        ]);
    }

    public function sendText(string $phone, string $text): array
    {
        $this->assertConnected();

        return $this->client->request('POST', 'messages/send-text', [
            'chatId' => $this->formatChatId($phone),
            'text'   => $text,
        ]);
    }

    public function sendImage(string $phone, string $url, ?string $caption = null): array
    {
        $this->assertConnected();

        $payload = [
            'chatId' => $this->formatChatId($phone),
            'url'    => $url,
        ];

        if ($caption !== null) {
            $payload['caption'] = $caption;
        }

        return $this->client->request('POST', 'messages/send-image', $payload);
    }

    public function sendVideo(string $phone, string $url, ?string $caption = null): array
    {
        $this->assertConnected();

        $payload = [
            'chatId' => $this->formatChatId($phone),
            'url'    => $url,
        ];

        if ($caption !== null) {
            $payload['caption'] = $caption;
        }

        return $this->client->request('POST', 'messages/send-video', $payload);
    }

    public function sendAudio(string $phone, string $url): array
    {
        $this->assertConnected();

        return $this->client->request('POST', 'messages/send-audio', [
            'chatId' => $this->formatChatId($phone),
            'url'    => $url,
        ]);
    }

    public function sendDocument(string $phone, string $url, ?string $filename = null): array
    {
        $this->assertConnected();

        $payload = [
            'chatId' => $this->formatChatId($phone),
            'url'    => $url,
        ];

        if ($filename !== null) {
            $payload['filename'] = $filename;
        }

        return $this->client->request('POST', 'messages/send-document', $payload);
    }

    public function sendLocation(string $phone, float $latitude, float $longitude, ?string $description = null): array
    {
        $this->assertConnected();

        $payload = [
            'chatId'    => $this->formatChatId($phone),
            'latitude'  => $latitude,
            'longitude' => $longitude,
        ];

        if ($description !== null) {
            $payload['description'] = $description;
        }

        return $this->client->request('POST', 'messages/send-location', $payload);
    }

    public function sendContact(string $phone, array $contact): array
    {
        $this->assertConnected();

        return $this->client->request('POST', 'messages/send-contact', [
            'chatId'  => $this->formatChatId($phone),
            'contact' => $contact,
        ]);
    }

    public function sendSticker(string $phone, string $url): array
    {
        $this->assertConnected();

        return $this->client->request('POST', 'messages/send-sticker', [
            'chatId' => $this->formatChatId($phone),
            'url'    => $url,
        ]);
    }

    public function sendBulk(array $messages): array
    {
        $this->assertConnected();

        return $this->client->request('POST', 'messages/send-bulk', [
            'messages' => $messages,
        ]);
    }

    public function formatChatId(string $phone): string
    {
        return PhoneNormalizer::toWhatsApp($phone) . '@c.us';
    }

    public function assertConnected(): void
    {
        if (!$this->isConnected()) {
            $dashboard = config('openwa.dashboard_url');

            throw new RuntimeException("WhatsApp not connected. Scan QR at {$dashboard}");
        }
    }
}
