<?php

namespace App\Services\EventHub;

use Stevebauman\Purify\Facades\Purify;

class HubContentSanitizer
{
    public function sanitizeHubConfig(array $hub_config): array
    {
        if (isset($hub_config['theme']) && is_array($hub_config['theme'])) {
            $hub_config['theme'] = $this->sanitizeArray($hub_config['theme']);
        }

        if (isset($hub_config['blocks']) && is_array($hub_config['blocks'])) {
            foreach ($hub_config['blocks'] as $index => $block) {
                if (!is_array($block)) {
                    continue;
                }

                if (isset($block['data']) && is_array($block['data'])) {
                    $block['data'] = $this->sanitizeArray($block['data']);
                }

                if (isset($block['id']) && is_string($block['id'])) {
                    $block['id'] = $this->sanitizeString($block['id']);
                }

                $hub_config['blocks'][$index] = $block;
            }
        }

        return $hub_config;
    }

    private function sanitizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->sanitizeString($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value);
            }
        }

        return $data;
    }

    private function sanitizeString(string $value): string
    {
        $clean = Purify::clean($value);

        return is_string($clean) ? trim($clean) : trim($value);
    }
}
