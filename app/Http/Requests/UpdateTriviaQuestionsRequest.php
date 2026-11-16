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

    protected function prepareForValidation(): void
    {
        $questions = $this->input('questions');
        if (!is_array($questions)) {
            return;
        }

        foreach ($questions as $index => $question) {
            if (!is_array($question)) {
                continue;
            }

            // ConvertEmptyStringsToNull turns prompt:'' into null before validation.
            if (!array_key_exists('prompt', $question) || $question['prompt'] === null) {
                $questions[$index]['prompt'] = '';
            }

            if (
                !array_key_exists('time_limit_seconds', $question)
                || $question['time_limit_seconds'] === null
                || $question['time_limit_seconds'] === ''
            ) {
                $questions[$index]['time_limit_seconds'] = 20;
            }

            if (isset($question['options']) && is_array($question['options'])) {
                foreach ($question['options'] as $option_index => $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    if (!array_key_exists('label', $option) || $option['label'] === null) {
                        $questions[$index]['options'][$option_index]['label'] = '';
                    }
                }
            }
        }

        $this->merge(['questions' => $questions]);
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
            "{$prefix}.type" => ['nullable', 'string', Rule::in(TriviaQuestionTypeEnum::playable())],
            "{$prefix}.prompt" => 'nullable|string|max:500',
            "{$prefix}.time_limit_seconds" => 'nullable|integer|min:5|max:120',
            "{$prefix}.max_points" => 'nullable|integer|min:0|max:10000',
            "{$prefix}.fun_fact" => 'nullable|string|max:280',
            "{$prefix}.media_asset_id" => 'nullable|integer',
            "{$prefix}.config" => 'nullable|array',
            "{$prefix}.options" => 'nullable|array|max:6',
            "{$prefix}.options.*.label" => 'nullable|string|max:200',
            "{$prefix}.options.*.is_correct" => 'nullable|boolean',
        ];
    }
}
