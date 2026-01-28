<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventGallerySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
           'selectedAlbum' => 'string|min:1|max:30',
        ];
    }
}
