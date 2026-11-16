<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrackEventAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'uuid'],
            'events' => ['required', 'array', 'min:1', 'max:20'],
            'events.*.name' => ['required', 'string', 'max:64'],
            'events.*.meta' => ['nullable', 'array'],
            'events.*.meta.count' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }
}
