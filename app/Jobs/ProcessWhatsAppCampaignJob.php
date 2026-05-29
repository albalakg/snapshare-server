<?php

namespace App\Jobs;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignGuest;
use App\Services\Enums\WhatsAppCampaignGuestStatusEnum;
use App\Services\Enums\WhatsAppCampaignStatusEnum;
use App\Services\Helpers\LogService;
use App\Services\Helpers\WhatsAppService;
use App\Services\OpenWa\OpenWaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessWhatsAppCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        protected int $campaignId,
    ) {}

    public function handle(WhatsAppService $whatsappService, OpenWaService $openWaService): void
    {
        $campaign = WhatsAppCampaign::find($this->campaignId);

        if (!$campaign) {
            return;
        }

        if (in_array($campaign->status, [
            WhatsAppCampaignStatusEnum::COMPLETED,
            WhatsAppCampaignStatusEnum::PARTIAL,
            WhatsAppCampaignStatusEnum::FAILED,
            WhatsAppCampaignStatusEnum::CANCELLED,
        ], true)) {
            return;
        }

        if ($campaign->status === WhatsAppCampaignStatusEnum::PENDING) {
            $campaign->status = WhatsAppCampaignStatusEnum::QUEUED;
            $campaign->queued_at = now();
            $campaign->save();
        }

        $campaign->status = WhatsAppCampaignStatusEnum::SENDING;
        $campaign->started_at = now();
        $campaign->save();

        if (!$openWaService->isConnected()) {
            $this->failCampaign($campaign, 'WhatsApp not connected. Scan QR at ' . config('openwa.dashboard_url'));

            return;
        }

        $recipients = WhatsAppCampaignGuest::where('campaign_id', $campaign->id)
            ->where('status', WhatsAppCampaignGuestStatusEnum::PENDING)
            ->with('guest')
            ->get();

        foreach ($recipients as $recipient) {
            $guest = $recipient->guest;

            if (!$guest) {
                $recipient->status = WhatsAppCampaignGuestStatusEnum::FAILED;
                $recipient->error_message = 'Guest not found';
                $recipient->save();
                continue;
            }

            $success = $whatsappService->sendText($guest->phone, $campaign->message);

            if ($success) {
                $recipient->status = WhatsAppCampaignGuestStatusEnum::SENT;
                $recipient->sent_at = now();
                $recipient->error_message = null;
            } else {
                $recipient->status = WhatsAppCampaignGuestStatusEnum::FAILED;
                $recipient->error_message = 'Failed to send message';
            }

            $recipient->save();
        }

        $sent_count = WhatsAppCampaignGuest::where('campaign_id', $campaign->id)
            ->where('status', WhatsAppCampaignGuestStatusEnum::SENT)
            ->count();

        $failed_count = WhatsAppCampaignGuest::where('campaign_id', $campaign->id)
            ->where('status', WhatsAppCampaignGuestStatusEnum::FAILED)
            ->count();

        $campaign->sent_count = $sent_count;
        $campaign->failed_count = $failed_count;
        $campaign->completed_at = now();

        if ($failed_count === 0 && $sent_count > 0) {
            $campaign->status = WhatsAppCampaignStatusEnum::COMPLETED;
        } elseif ($sent_count > 0) {
            $campaign->status = WhatsAppCampaignStatusEnum::PARTIAL;
        } else {
            $campaign->status = WhatsAppCampaignStatusEnum::FAILED;
        }

        $campaign->save();

        LogService::init()->info('WhatsApp campaign processed', [
            'campaign_id'  => $campaign->id,
            'event_id'     => $campaign->event_id,
            'sent_count'   => $sent_count,
            'failed_count' => $failed_count,
            'status'       => $campaign->status,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $campaign = WhatsAppCampaign::find($this->campaignId);

        if ($campaign) {
            $campaign->status = WhatsAppCampaignStatusEnum::FAILED;
            $campaign->completed_at = now();
            $campaign->save();
        }

        LogService::init()->error($exception, [
            'campaign_id' => $this->campaignId,
            'message'     => 'WhatsApp campaign job failed after all retries',
        ]);
    }

    private function failCampaign(WhatsAppCampaign $campaign, string $errorMessage): void
    {
        WhatsAppCampaignGuest::where('campaign_id', $campaign->id)
            ->where('status', WhatsAppCampaignGuestStatusEnum::PENDING)
            ->update([
                'status'        => WhatsAppCampaignGuestStatusEnum::FAILED,
                'error_message' => $errorMessage,
            ]);

        $failed_count = WhatsAppCampaignGuest::where('campaign_id', $campaign->id)
            ->where('status', WhatsAppCampaignGuestStatusEnum::FAILED)
            ->count();

        $campaign->sent_count = 0;
        $campaign->failed_count = $failed_count;
        $campaign->status = WhatsAppCampaignStatusEnum::FAILED;
        $campaign->completed_at = now();
        $campaign->save();

        LogService::init()->error($errorMessage, [
            'campaign_id' => $campaign->id,
            'event_id'    => $campaign->event_id,
        ]);
    }
}
