<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTriviaGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => 'required|boolean',
            'title' => 'required|string|min:1|max:80',
            'starts_at' => 'nullable|date',
            'results_at' => 'nullable|date',
        ];
    }
}
