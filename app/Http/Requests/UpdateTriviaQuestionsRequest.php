<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTriviaQuestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'questions' => 'present|array|max:20',
            'questions.*.prompt' => 'required|string|min:1|max:500',
            'questions.*.time_limit_seconds' => 'required|integer|min:5|max:120',
            'questions.*.max_points' => 'required|integer|min:100|max:10000',
            'questions.*.options' => 'required|array|min:2|max:6',
            'questions.*.options.*.label' => 'required|string|min:1|max:200',
            'questions.*.options.*.is_correct' => 'required|boolean',
        ];
    }
}
