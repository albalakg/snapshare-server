<?php

namespace App\Http\Requests;

use App\Services\Enums\TriviaQuestionTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatchTriviaQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        // ConvertEmptyStringsToNull turns prompt:'' into null before validation.
        if ($this->exists('prompt') && $this->input('prompt') === null) {
            $merge['prompt'] = '';
        }

        $options = $this->input('options');
        if (is_array($options)) {
            foreach ($options as $index => $option) {
                if (!is_array($option)) {
                    continue;
                }
                if (!array_key_exists('label', $option) || $option['label'] === null) {
                    $options[$index]['label'] = '';
                }
            }
            $merge['options'] = $options;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(TriviaQuestionTypeEnum::playable())],
            'prompt' => 'sometimes|nullable|string|max:500',
            'time_limit_seconds' => 'sometimes|nullable|integer|min:5|max:120',
            'max_points' => 'sometimes|integer|min:0|max:10000',
            'fun_fact' => 'nullable|string|max:280',
            'media_asset_id' => 'nullable|integer',
            'config' => 'nullable|array',
            'options' => 'sometimes|array|min:0|max:6',
            'options.*.label' => 'nullable|string|max:200',
            'options.*.is_correct' => 'nullable|boolean',
        ];
    }
}
