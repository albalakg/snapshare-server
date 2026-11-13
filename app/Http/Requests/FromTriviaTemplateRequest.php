<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FromTriviaTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_id' => 'required|string|max:64',
            'replace' => 'nullable|boolean',
        ];
    }
}
