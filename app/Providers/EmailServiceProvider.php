<?php

namespace App\Providers;

use App\Models\AddonPurchase;
use App\Models\AiSeatingRun;
use App\Models\EventAsset;
use App\Models\EventHub;
use App\Models\EventWhatsAppSetting;
use App\Models\User;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppTemplate;
use App\Services\Enums\AuthProviderEnum;
use App\Services\Mail\EmailTriggers;
use App\Services\Mail\MailV2;
use App\Services\Seating\SeatingAiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Model hooks for the v2 emails (t141u). Old values are read synchronously in the model event, the email is
 * handed off after the surrounding DB transaction commits (DB::afterCommit runs immediately when there is none).
 * Every hook is inert while EMAILS_V2_ENABLED is off.
 */
class EmailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $later = fn (callable $fn) => MailV2::enabled() ? DB::afterCommit($fn) : null;

        User::created(function (User $u) use ($later) {
            if ($u->auth_provider === AuthProviderEnum::GOOGLE) {
                $later(fn () => EmailTriggers::welcomeGoogle($u));
            }
        });
        User::updated(function (User $u) use ($later) {
            // A real change: there was a password before (sign-up / Google users get no "changed" email for the first one).
            if ($u->wasChanged('password') && $u->getOriginal('password') && $u->password) {
                $later(fn () => EmailTriggers::passwordChanged($u));
            }
        });

        AddonPurchase::updated(function (AddonPurchase $p) use ($later) {
            if ($p->wasChanged('credited_at') && $p->credited_at && !$p->getOriginal('credited_at')) {
                $later(fn () => EmailTriggers::addonCredited($p));
            }
            if ($p->wasChanged('status') && in_array($p->status, ['failed', 'cancelled'], true) && $p->getOriginal('status') === 'in_progress') {
                $later(fn () => EmailTriggers::addonFailed($p));
            }
        });

        EventHub::saved(function (EventHub $h) use ($later) {
            $publishedNow = $h->is_published && ($h->wasRecentlyCreated || ($h->wasChanged('is_published') && !$h->getOriginal('is_published')));
            $stdFirst = EmailTriggers::hasStd($h->hub_config) && ($h->wasRecentlyCreated || ($h->wasChanged('hub_config') && !EmailTriggers::hasStd($h->getOriginal('hub_config'))));
            if ($publishedNow || $stdFirst) {
                $later(fn () => EmailTriggers::hubSaved($h, $publishedNow, $stdFirst));
            }
        });

        EventAsset::created(fn (EventAsset $a) => $later(fn () => EmailTriggers::assetCreated($a)));

        EventWhatsAppSetting::created(fn (EventWhatsAppSetting $s) => $later(fn () => EmailTriggers::whatsappReady($s)));

        WhatsAppTemplate::updated(function (WhatsAppTemplate $t) use ($later) {
            if ($t->wasChanged('meta_status')) {
                $later(fn () => EmailTriggers::templateReviewed($t));
            }
        });

        WhatsAppSend::updated(function (WhatsAppSend $s) use ($later) {
            if ($s->wasChanged('status') && in_array($s->status, ['sent', 'partial', 'failed'], true)) {
                $later(fn () => EmailTriggers::sendFinished($s));
            }
        });

        AiSeatingRun::saved(function (AiSeatingRun $r) use ($later) {
            if (in_array($r->status, SeatingAiService::COUNTED_STATUSES, true) && ($r->wasRecentlyCreated || ($r->wasChanged('status') && !in_array($r->getOriginal('status'), SeatingAiService::COUNTED_STATUSES, true)))) {
                $later(fn () => EmailTriggers::aiRunCreated($r));
            }
        });
    }
}
