<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessWhatsAppCampaignJob;
use App\Models\EventGuest;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignGuest;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\WhatsAppCampaignGuestStatusEnum;
use App\Services\Enums\WhatsAppCampaignStatusEnum;
use App\Services\Enums\WhatsAppSendModeEnum;
use App\Services\Events\EventService;
use App\Services\Users\UserService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class WhatsAppCampaignService
{
    public const MAX_SENDS_PER_EVENT = 3;

    public function __construct(
        private ?EventService $event_service = null,
    ) {
        $this->event_service ??= new EventService(new UserService());
    }

    public function quota(int $event_id, int $user_id): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        $remaining = $this->remainingSends($event_id);

        return [
            'max_sends'         => self::MAX_SENDS_PER_EVENT,
            'used_sends'        => self::MAX_SENDS_PER_EVENT - $remaining,
            'remaining_sends'   => $remaining,
        ];
    }

    public function list(int $event_id, int $user_id): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        $campaigns = WhatsAppCampaign::where('event_id', $event_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (WhatsAppCampaign $campaign) => $this->formatCampaignSummary($campaign));

        return [
            'campaigns'       => $campaigns,
            'remaining_sends' => $this->remainingSends($event_id),
        ];
    }

    public function find(int $event_id, int $campaign_id, int $user_id): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $campaign = $this->findCampaignForEvent($event_id, $campaign_id);

        $recipients = WhatsAppCampaignGuest::where('campaign_id', $campaign->id)
            ->with('guest')
            ->get()
            ->map(function (WhatsAppCampaignGuest $row) {
                return [
                    'guest_id'      => $row->guest_id,
                    'full_name'     => $row->guest?->full_name,
                    'status'        => $row->status,
                    'sent_at'       => $row->sent_at,
                    'error_message' => $row->error_message,
                ];
            });

        return [
            'campaign'   => $this->formatCampaignSummary($campaign),
            'recipients' => $recipients,
        ];
    }

    public function create(int $event_id, int $user_id, array $data): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        if ($this->remainingSends($event_id) <= 0) {
            throw new Exception(MessagesEnum::WHATSAPP_SEND_LIMIT_REACHED);
        }

        $guest_ids = $this->resolveGuestIds($event_id, $data['guest_ids'] ?? null);

        if ($guest_ids === []) {
            throw new Exception(MessagesEnum::WHATSAPP_NO_GUESTS);
        }

        $send_mode = $data['send_mode'];
        $scheduled_at = null;

        if ($send_mode === WhatsAppSendModeEnum::SCHEDULED) {
            $scheduled_at = Carbon::parse($data['scheduled_at']);
        }

        return DB::transaction(function () use ($event_id, $user_id, $data, $guest_ids, $send_mode, $scheduled_at) {
            $campaign = WhatsAppCampaign::create([
                'event_id'        => $event_id,
                'user_id'         => $user_id,
                'message'         => $data['message'],
                'send_mode'       => $send_mode,
                'scheduled_at'    => $scheduled_at,
                'status'          => WhatsAppCampaignStatusEnum::PENDING,
                'recipient_count' => count($guest_ids),
            ]);

            foreach ($guest_ids as $guest_id) {
                WhatsAppCampaignGuest::create([
                    'campaign_id' => $campaign->id,
                    'guest_id'    => $guest_id,
                    'status'      => WhatsAppCampaignGuestStatusEnum::PENDING,
                ]);
            }

            if ($send_mode === WhatsAppSendModeEnum::IMMEDIATE) {
                $campaign->status = WhatsAppCampaignStatusEnum::QUEUED;
                $campaign->queued_at = now();
                $campaign->save();

                ProcessWhatsAppCampaignJob::dispatch($campaign->id);
            }

            return [
                'campaign'        => $this->formatCampaignSummary($campaign->fresh()),
                'remaining_sends' => $this->remainingSends($event_id),
            ];
        });
    }

    public function cancel(int $event_id, int $campaign_id, int $user_id): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $campaign = $this->findCampaignForEvent($event_id, $campaign_id);

        if ($campaign->status !== WhatsAppCampaignStatusEnum::PENDING) {
            throw new Exception(MessagesEnum::WHATSAPP_CAMPAIGN_NOT_CANCELLABLE);
        }

        $campaign->status = WhatsAppCampaignStatusEnum::CANCELLED;
        $campaign->completed_at = now();
        $campaign->save();

        return [
            'campaign'        => $this->formatCampaignSummary($campaign),
            'remaining_sends' => $this->remainingSends($event_id),
        ];
    }

    public function dispatchDueScheduledCampaigns(): int
    {
        $campaigns = WhatsAppCampaign::where('status', WhatsAppCampaignStatusEnum::PENDING)
            ->where('send_mode', WhatsAppSendModeEnum::SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        $dispatched = 0;

        foreach ($campaigns as $campaign) {
            $campaign->status = WhatsAppCampaignStatusEnum::QUEUED;
            $campaign->queued_at = now();
            $campaign->save();

            ProcessWhatsAppCampaignJob::dispatch($campaign->id);
            $dispatched++;
        }

        return $dispatched;
    }

    private function remainingSends(int $event_id): int
    {
        $used = WhatsAppCampaign::where('event_id', $event_id)
            ->where('status', '!=', WhatsAppCampaignStatusEnum::CANCELLED)
            ->count();

        return max(0, self::MAX_SENDS_PER_EVENT - $used);
    }

    /**
     * @return int[]
     */
    private function resolveGuestIds(int $event_id, ?array $guest_ids): array
    {
        if ($guest_ids === null || $guest_ids === []) {
            return EventGuest::where('event_id', $event_id)->pluck('id')->all();
        }

        $valid_ids = EventGuest::where('event_id', $event_id)
            ->whereIn('id', $guest_ids)
            ->pluck('id')
            ->all();

        if (count($valid_ids) !== count(array_unique($guest_ids))) {
            throw new Exception(MessagesEnum::WHATSAPP_GUEST_NOT_FOUND);
        }

        return $valid_ids;
    }

    private function findCampaignForEvent(int $event_id, int $campaign_id): WhatsAppCampaign
    {
        $campaign = WhatsAppCampaign::where('event_id', $event_id)->where('id', $campaign_id)->first();

        if (!$campaign) {
            throw new Exception(MessagesEnum::WHATSAPP_CAMPAIGN_NOT_FOUND);
        }

        return $campaign;
    }

    private function formatCampaignSummary(WhatsAppCampaign $campaign): array
    {
        return [
            'id'              => $campaign->id,
            'message'         => $campaign->message,
            'send_mode'       => $campaign->send_mode,
            'scheduled_at'    => $campaign->scheduled_at,
            'status'          => $campaign->status,
            'recipient_count' => $campaign->recipient_count,
            'sent_count'      => $campaign->sent_count,
            'failed_count'    => $campaign->failed_count,
            'queued_at'       => $campaign->queued_at,
            'started_at'      => $campaign->started_at,
            'completed_at'    => $campaign->completed_at,
            'created_at'      => $campaign->created_at,
        ];
    }
}
