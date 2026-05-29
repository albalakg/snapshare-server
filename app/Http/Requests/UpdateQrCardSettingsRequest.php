<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQrCardSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'design' => ['nullable', 'string', Rule::in(config('qr_card.designs', []))],
            'text'   => ['nullable', 'string', 'max:200'],
        ];
    }
}
