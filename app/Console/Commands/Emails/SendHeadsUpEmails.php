<?php

namespace App\Console\Commands\Emails;

use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Services\Mail\EmailTriggers;
use App\Services\Mail\MailV2;
use Illuminate\Console\Command;

/** I3: ~1 hour before a scheduled WhatsApp send to ≥ N recipients — the host can still edit / postpone / cancel. */
class SendHeadsUpEmails extends Command
{
    protected $signature = 'emails:heads-up';

    protected $description = 'Heads-up email before large scheduled WhatsApp sends (emails v2)';

    public function handle(): int
    {
        if (!MailV2::enabled()) {
            return self::SUCCESS;
        }
        $minutes = (int) config('emails.triggers.heads_up_minutes', 60);
        $min = (int) config('emails.triggers.heads_up_min_recipients', 20);
        // Window [m-10, m+5] min with a 5-minute schedule: each send is caught once; the dedupe key includes scheduled_at.
        $sends = WhatsAppSend::where('status', 'scheduled')
            ->whereBetween('scheduled_at', [now()->addMinutes($minutes - 10), now()->addMinutes($minutes + 5)])
            ->get();
        foreach ($sends as $send) {
            $n = WhatsAppSendRecipient::where('send_id', $send->id)->whereIn('status', ['pending', 'sending'])->count();
            if ($n >= $min) {
                EmailTriggers::headsUp($send, $n);
            }
        }

        return self::SUCCESS;
    }
}
