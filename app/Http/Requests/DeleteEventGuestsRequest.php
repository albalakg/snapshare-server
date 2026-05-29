<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteEventGuestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guest_id'   => 'required_without:guest_ids|integer|min:1',
            'guest_ids'  => 'required_without:guest_id|array|min:1|max:5000',
            'guest_ids.*' => 'integer|min:1',
        ];
    }

    public function guestIds(): array
    {
        if ($this->filled('guest_ids')) {
            return array_values(array_unique($this->input('guest_ids')));
        }

        return [(int) $this->input('guest_id')];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('guest_id') && !$this->has('guest_ids') && $this->query('guest_id')) {
            $this->merge(['guest_id' => $this->query('guest_id')]);
        }
    }
}
