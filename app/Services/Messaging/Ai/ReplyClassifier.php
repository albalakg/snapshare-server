<?php

namespace App\Services\Messaging\Ai;

interface ReplyClassifier
{
    public function name(): string;

    public function model(): ?string;

    /**
     * @param array{rules: array, event_title?: string, guest_first_name?: string, party_size?: int, max_party_size?: int, meal_options?: array} $context
     * @return array{intent: string, suggestion: ?array, confidence: ?float, note: ?string}
     */
    public function classify(string $text, array $context): array;
}
