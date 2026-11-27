<?php

namespace App\Console\Commands\Emails;

use App\Models\MailDelivery;
use App\Services\Helpers\LogService;
use App\Services\Mail\MailLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** Sends emails held by MailPolicy (night quiet hours / Shabbat) once their send_at has passed. */
class SendDeferredEmails extends Command
{
    protected $signature = 'emails:send-deferred {--limit=100}';

    protected $description = 'Send emails deferred by the quiet-hours / Shabbat policy (mail_deliveries)';

    public function handle(): int
    {
        if (!MailLedger::available()) {
            return self::SUCCESS;
        }
        $active = Str::lower(trim((string) config('mail.status'))) === 'active';
        $rows = MailDelivery::where('status', 'deferred')->where('send_at', '<=', now())->orderBy('send_at')->limit((int) $this->option('limit'))->get();
        $sent = 0;
        foreach ($rows as $row) {
            // Atomic claim so overlapping runs never double-send.
            if (!MailDelivery::whereKey($row->id)->where('status', 'deferred')->update(['status' => 'sending'])) {
                continue;
            }
            try {
                if (!$active) {
                    $row->update(['status' => 'skipped_inactive']);
                    continue;
                }
                $mailable = unserialize(base64_decode((string) $row->payload));
                Mail::to(array_filter(array_map('trim', explode(',', $row->recipient))))->send($mailable);
                $row->update(['status' => 'sent', 'sent_at' => now(), 'payload' => null]);
                $sent++;
            } catch (Throwable $e) {
                $row->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                LogService::init()->error('Deferred email failed', ['id' => $row->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("deferred sent: {$sent}");

        return self::SUCCESS;
    }
}
