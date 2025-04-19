<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            // 'description' => 'sometimes|nullable|string|max:1000',
            'starts_at' => 'sometimes|required|date|after:now',
            'image' => [
                'nullable',
                'file',
                'mimes:jpeg,png,jpg,webp',
                'max:10240',
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
        ];
    }
}
