<?php

namespace App\Http\Requests;

use App\Services\Enums\WhatsAppSendModeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWhatsAppCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message'      => 'required|string|min:1|max:4096',
            'send_mode'    => ['required', 'string', Rule::in(WhatsAppSendModeEnum::getAllValues())],
            'scheduled_at' => 'required_if:send_mode,scheduled|nullable|date|after:now',
            'guest_ids'    => 'nullable|array|min:1',
            'guest_ids.*'  => 'integer|min:1',
        ];
    }
}
