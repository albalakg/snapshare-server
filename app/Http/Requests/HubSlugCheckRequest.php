<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HubSlugCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('slug') && $this->query('slug') !== null) {
            $this->merge(['slug' => $this->query('slug')]);
        }
    }

    public function rules(): array
    {
        return [
            'slug' => 'required|string',
        ];
    }
}
