<?php

namespace App\Http\Requests;

use App\Services\Enums\TriviaPhaseEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetTriviaPhaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phase' => ['required', 'string', Rule::in(TriviaPhaseEnum::all())],
            'question_id' => 'nullable|integer',
        ];
    }
}
