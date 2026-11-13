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

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(TriviaQuestionTypeEnum::mvp())],
            'prompt' => 'sometimes|string|min:1|max:500',
            'time_limit_seconds' => 'sometimes|integer|min:5|max:120',
            'max_points' => 'sometimes|integer|min:0|max:10000',
            'fun_fact' => 'nullable|string|max:280',
            'media_asset_id' => 'nullable|integer',
            'config' => 'nullable|array',
            'options' => 'sometimes|array|min:0|max:6',
            'options.*.label' => 'required_with:options|string|min:1|max:200',
            'options.*.is_correct' => 'nullable|boolean',
        ];
    }
}
