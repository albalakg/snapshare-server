<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SeatingAiRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'engine'          => 'sometimes|in:ai,packer',
            'mode'            => 'sometimes|in:fill,reshuffle',
            'include_pending' => 'sometimes|boolean',
            'include_maybe'   => 'sometimes|boolean',
            'instructions'    => 'sometimes|nullable|string|max:500',
        ];
    }
}
