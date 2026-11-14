<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HubAssetsIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (!$this->has('page') && $this->query('page') !== null) {
            $merge['page'] = $this->query('page');
        }

        if (!$this->has('per_page') && $this->query('per_page') !== null) {
            $merge['per_page'] = $this->query('per_page');
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
        ];
    }
}
