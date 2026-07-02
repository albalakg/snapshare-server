<?php

namespace App\Http\Requests;

use App\Services\Enums\IcebreakerStatusEnum;
use App\Services\Enums\MatchIntentEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIcebreakerConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'           => ['required', 'integer', Rule::in(IcebreakerStatusEnum::getAllValues())],
            'allowed_intents'  => 'required|array|min:1',
            'allowed_intents.*' => ['string', Rule::in(MatchIntentEnum::validKeys())],
        ];
    }
}
