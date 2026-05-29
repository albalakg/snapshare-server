<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessWhatsAppCampaignJob;
use App\Models\EventGuest;
use App\Models\WhatsAppCampaign;
use App\Services\Enums\WhatsAppCampaignGuestStatusEnum;
use App\Services\Enums\WhatsAppCampaignStatusEnum;
use App\Services\Enums\WhatsAppSendModeEnum;
use App\Services\Guests\PhoneNormalizer;
use App\Services\WhatsApp\WhatsAppCampaignService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$eventId = 3;
$userId = 4;
$testPhone = '972528458600';

$phoneHash = PhoneNormalizer::hash($testPhone);

$guest = EventGuest::withTrashed()
    ->where('event_id', $eventId)
    ->where('phone_hash', $phoneHash)
    ->first();

if ($guest?->trashed()) {
    $guest->restore();
    echo "Restored guest #{$guest->id}\n";
}

if (!$guest) {
    $guest = EventGuest::create([
        'event_id'   => $eventId,
        'full_name'  => 'Queue Test',
        'phone'      => $testPhone,
        'phone_hash' => PhoneNormalizer::hash($testPhone),
        'status'     => 0,
        'party_size' => 1,
    ]);
    echo "Created test guest #{$guest->id}\n";
} else {
    echo "Using existing guest #{$guest->id}\n";
}

$service = new WhatsAppCampaignService();
$result = $service->create($eventId, $userId, [
    'message'      => 'Scheduled queue verify - ' . now()->toDateTimeString(),
    'send_mode'    => WhatsAppSendModeEnum::SCHEDULED,
    'scheduled_at' => now()->subMinute()->toDateTimeString(),
    'guest_ids'    => [$guest->id],
]);

$campaignId = $result['campaign']['id'];
echo "Campaign #{$campaignId} created.\n";

$dispatched = $service->dispatchDueScheduledCampaigns();
echo "Dispatched {$dispatched} due campaign(s).\n";

while (DB::table('jobs')->where('payload', 'like', '%ProcessWhatsAppCampaignJob%')->exists()) {
    Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);
}

$campaign = WhatsAppCampaign::find($campaignId);
$recipient = DB::table('whatsapp_campaign_guests')->where('campaign_id', $campaignId)->first();

echo json_encode([
    'campaign_status' => $campaign->status,
    'sent_count'      => $campaign->sent_count,
    'failed_count'    => $campaign->failed_count,
    'recipient'       => $recipient,
], JSON_PRETTY_PRINT) . "\n";

$ok = $campaign->status === WhatsAppCampaignStatusEnum::COMPLETED
    && $campaign->sent_count === 1
    && $recipient->status === WhatsAppCampaignGuestStatusEnum::SENT;

exit($ok ? 0 : 1);
