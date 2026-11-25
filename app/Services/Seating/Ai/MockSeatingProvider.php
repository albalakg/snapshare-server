<?php

namespace App\Services\Seating\Ai;

use App\Services\Seating\SeatingPacker;

/**
 * Local stand-in for the AI provider (no API key needed). Deterministic: it runs the
 * packer on the same compact input and reports estimated tokens, cost 0.
 */
class MockSeatingProvider implements SeatingAiProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function model(): string
    {
        return 'mock-seating-v1';
    }

    public function propose(array $input): array
    {
        $tables = array_map(fn ($t) => ['id' => $t['id'], 'label' => $t['label'], 'seats' => $t['free'], 'side' => $t['side']], $input['tables']);
        $guests = array_map(fn ($g) => [
            'id' => $g['id'], 'name' => $g['name'], 'party' => $g['party_size'],
            'group_id' => $g['group_id'], 'group_name' => $g['group'], 'side' => $g['side'],
        ], $input['guests']);
        $constraints = array_map(fn ($c) => ['type' => $c['type'], 'guest_ids' => $c['guest_ids']], $input['constraints']);

        $result = (new SeatingPacker())->pack($tables, $guests, $constraints, []);

        $prompt = SeatingAiPrompt::system() . SeatingAiPrompt::user($input);
        $out = json_encode($result, JSON_UNESCAPED_UNICODE);

        return [
            'assignments' => array_map(fn ($a) => [
                'guest_id' => $a['guest_id'],
                'table_id' => $a['table_id'],
                'reason'   => $a['reason'],
            ], $result['assignments']),
            'unplaced'    => $result['unplaced'],
            'tokens_in'   => SeatingAiPrompt::estimateTokens($prompt),
            'tokens_out'  => SeatingAiPrompt::estimateTokens($out),
            'model'       => $this->model(),
        ];
    }
}
