<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublishEventHubRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_published' => 'required|boolean',
            'slug'         => 'nullable|string|max:100',
        ];
    }
}
