<?php

namespace App\Services\Seating\Ai;

interface SeatingAiProvider
{
    /**
     * @param array $input compact seating problem (see SeatingAiService::buildInput)
     * @return array ['assignments' => [['guest_id','table_id','reason']], 'unplaced' => [['guest_id','reason']],
     *                'tokens_in' => int, 'tokens_out' => int, 'model' => string]
     * @throws \Throwable on provider failure (run is marked failed and not counted)
     */
    public function propose(array $input): array;

    public function name(): string;

    public function model(): string;
}
