<?php

namespace App\Http\Requests;

use App\Services\Enums\EventGalleryTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventGallerySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selectedAlbum' => [
                'required',
                'string',
                'min:1',
                'max:30',
                Rule::in(EventGalleryTypeEnum::allowedValues()),
            ],
            'live_screen_options' => ['nullable', 'array'],
            'live_screen_options.qr_corner' => [
                'nullable',
                'string',
                Rule::in(['bottom-start', 'bottom-end', 'top-start', 'top-end']),
            ],
            'live_screen_options.ken_burns_intensity' => [
                'nullable',
                'string',
                Rule::in(['subtle', 'medium']),
            ],
            'live_screen_options.mosaic_size' => [
                'nullable',
                'string',
                Rule::in(['3x3', '4x4']),
            ],
            'live_screen_options.masonry_density' => [
                'nullable',
                'string',
                Rule::in(['cozy', 'dense']),
            ],
            'live_screen_options.polaroid_count' => ['nullable', 'integer', 'min:4', 'max:16'],
            'live_screen_options.spotlight_hold_seconds' => ['nullable', 'integer', 'min:2', 'max:30'],
        ];
    }
}
