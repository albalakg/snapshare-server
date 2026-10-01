<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinTriviaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nickname' => 'required|string|min:2|max:40',
            'image' => 'nullable|file|image|mimes:jpeg,jpg,png,webp|max:2048',
        ];
    }
}
