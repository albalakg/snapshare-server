<?php

namespace App\Http\Requests;

use App\Services\Enums\EventGalleryTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            // 'description' => 'sometimes|nullable|string|max:1000',
            'starts_at' => 'sometimes|required|date|after:now',
            'event_type' => [
                'nullable',
                'string',
                'in:wedding,barmitzvah,birthday,brit,corporate,party,festival',
            ],
            'image' => [
                'nullable',
                'file',
                'max:10240', // 10MB
                'mimetypes:image/*',
            ],
            'config.preview_site_display_image'         => ['nullable', 'in:true,false'],
            'config.preview_site_display_name'          => ['nullable', 'in:true,false'],
            'config.preview_site_display_date'          => ['nullable', 'in:true,false'],
            'config.preview_guests_assets_in_gallery'   => ['nullable', 'in:true,false'],
            'config.preview_owners_assets_in_gallery'   => ['nullable', 'in:true,false'],
            'config.preview_qr_in_gallery'                          => ['nullable', 'in:true,false'],
            'config.video_upload_enabled'                           => ['nullable', 'in:true,false'],
            'config.preview_link_to_album_page_from_upload_page'    => ['nullable', 'in:true,false'],
            'config.manual_photo_approval'                          => ['nullable', 'in:true,false'],
            'config.welcome_text'                                   => ['nullable', 'string', 'max:255'],
            'config.image_display_time'                             => ['nullable', 'integer', 'min:1', 'max:120'],
            'config.video_display_time'                             => ['nullable', 'integer', 'min:1', 'max:300'],
            'config.displayed_gallery' => [
                'nullable',
                'string',
                'max:30',
                Rule::in(EventGalleryTypeEnum::allowedValues()),
            ],
            'config.live_screen_options' => ['nullable', 'array'],
            'config.live_screen_options.qr_corner' => [
                'nullable',
                'string',
                Rule::in(['bottom-start', 'bottom-end', 'top-start', 'top-end']),
            ],
            'config.live_screen_options.ken_burns_intensity' => [
                'nullable',
                'string',
                Rule::in(['subtle', 'medium']),
            ],
            'config.live_screen_options.mosaic_size' => [
                'nullable',
                'string',
                Rule::in(['3x3', '4x4']),
            ],
            'config.live_screen_options.masonry_density' => [
                'nullable',
                'string',
                Rule::in(['cozy', 'dense']),
            ],
            'config.live_screen_options.polaroid_count' => ['nullable', 'integer', 'min:4', 'max:16'],
            'config.live_screen_options.spotlight_hold_seconds' => ['nullable', 'integer', 'min:2', 'max:30'],
        ];
    }
}
