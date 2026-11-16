<?php

namespace App\Http\Requests;

use App\Services\Enums\TriviaPaceEnum;
use App\Services\Trivia\TriviaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTriviaGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => 'required|boolean',
            'title' => 'required|string|min:1|max:80',
            'starts_at' => 'nullable|date',
            'results_at' => 'nullable|date',
            'theme' => ['nullable', 'string', Rule::in(TriviaService::THEMES)],
            'pace' => ['nullable', 'string', Rule::in(TriviaPaceEnum::all())],
            'teams_mode' => ['nullable', 'string', Rule::in(TriviaService::TEAMS_MODES)],
            'team_labels' => 'nullable|array',
            'team_labels.a' => 'nullable|string|max:40',
            'team_labels.b' => 'nullable|string|max:40',
            'speed_bonus' => 'nullable|boolean',
            'prize_text' => 'nullable|string|max:160',
            'reveal_seconds' => 'nullable|integer|min:2|max:60',
            'default_time' => 'nullable|integer|min:5|max:120',
            'default_points' => 'nullable|integer|min:0|max:10000',
        ];
    }
}
