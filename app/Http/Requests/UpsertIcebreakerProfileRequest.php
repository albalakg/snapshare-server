<?php

namespace App\Http\Requests;

use App\Services\Enums\GenderEnum;
use App\Services\Enums\MatchIntentEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertIcebreakerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name'      => 'required|string|min:1|max:50',
            'avatar_url'        => 'required|url|max:2048',
            'gender'            => ['nullable', 'string', Rule::in(GenderEnum::validValues())],
            'target_genders'    => 'nullable|array',
            'target_genders.*'  => ['string', Rule::in(GenderEnum::validValues())],
            'primary_intent'    => ['required', 'string', Rule::in(MatchIntentEnum::validKeys())],
            'bio'               => 'nullable|string|max:150',
            'instagram_handle'  => 'nullable|string|max:50|regex:/^[a-zA-Z0-9._]{1,50}$/',
            'whatsapp_number'   => 'nullable|string|max:20',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('whatsapp_number') && $this->whatsapp_number !== null) {
            $this->merge([
                'whatsapp_number' => preg_replace('/\s+/', '', (string) $this->whatsapp_number),
            ]);
        }
    }
}
