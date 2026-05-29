<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name'  => 'sometimes|required|string|min:1|max:255',
            'phone'      => 'sometimes|required|string|regex:/^\d{7,15}$/',
            'email'      => 'nullable|email|max:255',
            'status'     => 'nullable|integer|min:0|max:4',
            'party_size' => 'nullable|integer|min:1|max:20',
            'group_key'  => 'nullable|uuid',
            'metadata'   => 'nullable|array',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge([
                'phone' => preg_replace('/\D+/', '', (string) $this->phone) ?? '',
            ]);
        }
    }
}
