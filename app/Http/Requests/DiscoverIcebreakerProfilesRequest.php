<?php

namespace App\Http\Requests;

use App\Services\Enums\MatchIntentEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscoverIcebreakerProfilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit'  => 'nullable|integer|min:1|max:50',
            'intent' => ['nullable', 'string', Rule::in(MatchIntentEnum::validKeys())],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->input('limit', 20));
    }
}
