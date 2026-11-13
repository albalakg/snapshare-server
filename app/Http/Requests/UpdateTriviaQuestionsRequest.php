<?php

namespace App\Http\Requests;

use App\Services\Enums\TriviaQuestionTypeEnum;
use App\Services\Trivia\TriviaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTriviaQuestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'questions' => 'present|array|max:' . TriviaService::MAX_QUESTIONS,
        ], $this->questionItemRules('questions.*'));
    }

    public static function questionItemRules(string $prefix): array
    {
        return [
            "{$prefix}.type" => ['nullable', 'string', Rule::in(TriviaQuestionTypeEnum::mvp())],
            "{$prefix}.prompt" => 'required|string|min:1|max:500',
            "{$prefix}.time_limit_seconds" => 'required|integer|min:5|max:120',
            "{$prefix}.max_points" => 'nullable|integer|min:0|max:10000',
            "{$prefix}.fun_fact" => 'nullable|string|max:280',
            "{$prefix}.media_asset_id" => 'nullable|integer',
            "{$prefix}.config" => 'nullable|array',
            "{$prefix}.options" => 'nullable|array|max:6',
            "{$prefix}.options.*.label" => 'required_with:' . $prefix . '.options|string|min:1|max:200',
            "{$prefix}.options.*.is_correct" => 'nullable|boolean',
        ];
    }
}
