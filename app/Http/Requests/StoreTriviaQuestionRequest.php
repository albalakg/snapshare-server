<?php

namespace App\Http\Requests;

use App\Services\Enums\TriviaQuestionTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTriviaQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        // ConvertEmptyStringsToNull turns prompt:'' into null before validation.
        if (!$this->exists('prompt') || $this->input('prompt') === null) {
            $merge['prompt'] = '';
        }

        if (!$this->exists('time_limit_seconds') || $this->input('time_limit_seconds') === null || $this->input('time_limit_seconds') === '') {
            $merge['time_limit_seconds'] = 20;
        }

        $options = $this->input('options');
        if (is_array($options)) {
            $merge['options'] = $this->coalesceOptionLabels($options);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(TriviaQuestionTypeEnum::playable())],
            'prompt' => 'nullable|string|max:500',
            'time_limit_seconds' => 'nullable|integer|min:5|max:120',
            'max_points' => 'nullable|integer|min:0|max:10000',
            'fun_fact' => 'nullable|string|max:280',
            'media_asset_id' => 'nullable|integer',
            'config' => 'nullable|array',
            'options' => 'nullable|array|max:6',
            'options.*.label' => 'nullable|string|max:200',
            'options.*.is_correct' => 'nullable|boolean',
        ];
    }

    /**
     * @param array<int, mixed> $options
     * @return array<int, mixed>
     */
    private function coalesceOptionLabels(array $options): array
    {
        foreach ($options as $index => $option) {
            if (!is_array($option)) {
                continue;
            }
            if (!array_key_exists('label', $option) || $option['label'] === null) {
                $options[$index]['label'] = '';
            }
        }

        return $options;
    }
}
