<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitTriviaAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_id' => 'required|integer',
            'option_ids' => 'present|array|max:6',
            'option_ids.*' => 'integer',
        ];
    }
}
