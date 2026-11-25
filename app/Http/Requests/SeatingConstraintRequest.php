<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SeatingConstraintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'type'        => $required . '|in:together,apart',
            'guest_ids'   => $required . '|array|min:2|max:30',
            'guest_ids.*' => 'integer|min:1',
            'note'        => 'sometimes|nullable|string|max:200',
        ];
    }
}
