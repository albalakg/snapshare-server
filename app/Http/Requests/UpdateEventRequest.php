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
                'max:10240', // 10MB
                'mimetypes:image/*', 
            ],
            'config.preview_site_display_image' => ['nullable', 'in:true,false'],
            'config.preview_site_display_name'  => ['nullable', 'in:true,false'],
            'config.preview_site_display_date'  => ['nullable', 'in:true,false'],
        ];
    }
}
