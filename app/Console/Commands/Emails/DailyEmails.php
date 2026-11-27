<?php

namespace App\Console\Commands\Emails;

use App\Models\Event;
use App\Services\Enums\StatusEnum;
use App\Services\Mail\EmailTriggers;
use App\Services\Mail\MailV2;
use Illuminate\Console\Command;

/**
 * 09:00 Israel time: RSVP digest (I5), table links ready (H2), seating nudge (H1), replies to review (I6), setup nudge (C1).
 * The 1/day cap (MailPolicy, digest category) keeps it to one of these per host per day, in this priority order.
 */
class DailyEmails extends Command
{
    protected $signature = 'emails:daily {--event= : only this event id}';

    protected $description = 'Daily host digests and nudges (emails v2)';

    public function handle(): int
    {
        if (!MailV2::enabled()) {
            $this->info('EMAILS_V2_ENABLED is off — nothing to do.');
            return self::SUCCESS;
        }

        $q = Event::query()->whereIn('status', [StatusEnum::PENDING, StatusEnum::READY, StatusEnum::ACTIVE, StatusEnum::IN_PROGRESS]);
        if ($this->option('event')) {
            $q->whereKey((int) $this->option('event'));
        }

        // Upcoming events (next 60 days): I5, H2, H1, I6.
        (clone $q)->whereNotNull('starts_at')->whereBetween('starts_at', [now(), now()->addDays(60)])->with('user')
            ->chunkById(100, function ($events) {
                foreach ($events as $event) {
                    $digest = EmailTriggers::rsvpDigest($event);
                    EmailTriggers::tableLinks($event);
                    EmailTriggers::seatingNudge($event);
                    if (!$digest) {
                        EmailTriggers::repliesReview($event);
                    }
                }
            });

        // Recently created events: C1 (day 2 and day 7).
        (clone $q)->where('created_at', '>=', now()->subDays(8))->with('user', 'hub')
            ->chunkById(100, function ($events) {
                foreach ($events as $event) {
                    EmailTriggers::setupNudge($event);
                }
            });

        $this->info('daily emails done');

        return self::SUCCESS;
    }
}
