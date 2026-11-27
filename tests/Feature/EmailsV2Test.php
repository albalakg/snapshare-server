<?php

namespace Tests\Feature;

use App\Mail\V2\AddonReceiptMail;
use App\Mail\V2\AdminAddonPurchaseMail;
use App\Mail\V2\AddonFailedMail;
use App\Mail\V2\HubPublishedMail;
use App\Mail\V2\PasswordChangedMail;
use App\Mail\V2\SendSummaryMail;
use App\Mail\V2\SendProblemMail;
use App\Mail\V2\WelcomeGoogleMail;
use App\Mail\V2\WhatsAppReadyMail;
use App\Models\EventHub;
use App\Models\MailDelivery;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Services\Enums\MailEnum;
use App\Services\Enums\SubscriptionEnum;
use App\Services\Events\RetentionService;
use App\Services\Helpers\MailService;
use App\Services\Mail\MailCatalog;
use App\Services\Mail\MailLedger;
use App\Services\Mail\MailPolicy;
use App\Services\Mail\MailTheme;
use App\Services\Messaging\MessagingContext;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\CreatesSeatingHost;
use Tests\TestCase;

/** Emails v2 (t141u / t142u / t144u). Never sends: Mail::fake + MAIL_MAILER=array. */
class EmailsV2Test extends TestCase
{
    use DatabaseTransactions;
    use CreatesSeatingHost;

    protected function setUp(): void
    {
        parent::setUp();
        MailLedger::reset();
        config([
            'emails.v2_enabled' => true, 'emails.policy.enabled' => false, 'mail.status' => 'active',
            'app.client_url' => 'http://client.test', 'app.url' => 'http://api.test',
            'mail.brand.support_email' => 'hello@snapshare-live.com',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mailed(string $class): int
    {
        return Mail::sent($class)->count() + Mail::queued($class)->count();
    }

    private function allVariants(): array
    {
        $out = [];
        foreach (MailCatalog::keys() as $k) {
            foreach (MailCatalog::variants($k) as $v) {
                $out[] = [$k, $v];
            }
        }
        return $out;
    }

    private function assertCleanHtml(string $html, string $label): void
    {
        $text = strip_tags($html);
        $this->assertStringNotContainsString('{{', $html, $label);
        $this->assertStringNotContainsString('{!!', $html, $label);
        $this->assertStringNotContainsString('Undefined', $html, $label);
        $this->assertDoesNotMatchRegularExpression('/\$[a-z_]+/', $text, $label . ': unresolved variable');
        $this->assertDoesNotMatchRegularExpression('/@(if|else|endif|foreach|php)\b/', $text, $label . ': raw blade');
        $this->assertStringNotContainsString('support@', $html, $label);
    }

    public function test_catalog_has_all_planned_emails_and_no_marketing(): void
    {
        $ids = array_column(array_map(fn ($k) => MailCatalog::get($k), MailCatalog::keys()), 'id');
        $this->assertCount(41, $ids);
        $this->assertCount(41, array_unique($ids));
        $this->assertNotContains('B5', $ids, 'abandoned checkout is marketing (t142u)');
        $this->assertNotContains('F5', $ids, 'feedback email is marketing (t142u)');
        $this->assertCount(23, array_filter(MailCatalog::ITEMS, fn ($i) => $i[3]));
        foreach (MailEnum::getAllValues() as $class) {
            $this->assertNotNull(MailCatalog::meta($class), $class . ' missing from MailCatalog');
        }
    }

    public function test_every_email_renders_in_the_v2_layout(): void
    {
        foreach ($this->allVariants() as [$key, $variant]) {
            $label = $key . ($variant ? ":$variant" : '');
            $m = MailCatalog::make($key, $variant);
            $subject = $m->mailSubject();
            $html = $m->render();
            $this->assertNotSame('', trim($subject), $label);
            $this->assertSame($subject, $m->envelope()->subject, $label);
            $this->assertStringContainsString('dir="rtl"', $html, $label);
            $this->assertStringContainsString('lang="he"', $html, $label);
            $this->assertStringContainsString('hello@snapshare-live.com', $html, $label . ': footer contact');
            $this->assertStringContainsString('SnapShare', $html, $label);
            $this->assertStringContainsString('<title>' . e($subject) . '</title>', $html, $label);
            $this->assertStringNotContainsString('פרסומת', $html, $label . ': transactional, no ad label');
            $this->assertStringNotContainsString('unsubscribe', strtolower($html), $label);
            $this->assertCleanHtml($html, $label);
        }
    }

    public function test_primary_button_has_outlook_vml_fallback_and_preheader(): void
    {
        $html = MailCatalog::make('order_confirmed')->render();
        $this->assertStringContainsString('v:roundrect', $html);
        $this->assertStringContainsString('display:none', $html);
        $this->assertStringContainsString('prefers-color-scheme:dark', $html);
    }

    public function test_legacy_templates_still_render_when_the_flag_is_off(): void
    {
        config(['emails.v2_enabled' => false]);
        foreach ($this->allVariants() as [$key, $variant]) {
            if (MailCatalog::get($key)['new']) {
                continue;
            }
            $m = MailCatalog::make($key, $variant);
            $this->assertNotSame('', trim($m->mailSubject()), $key);
            $html = $m->render();
            $this->assertStringNotContainsString('Undefined', $html, $key);
        }
    }

    public function test_subjects_match_the_plan(): void
    {
        $expect = [
            'user_signup' => 'אמתו את הכתובת והתחילו עם SnapShare',
            'forgot_password' => 'איפוס הסיסמה ל־SnapShare',
            'order_confirmed' => 'ההזמנה אושרה — האירוע שלכם מוכן',
            'event_tomorrow' => 'מחר זה קורה: החתונה של נועה ואיתי',
            'event_in_5_days' => 'עוד 5 ימים: החתונה של נועה ואיתי',
            'deactivation_warning' => 'עוד 3 ימים והקבצים של החתונה של נועה ואיתי יימחקו',
            'subscription_upgraded' => 'החבילה שודרגה ל־Classic',
            'rsvp_digest' => '23 תשובות חדשות לאישורי ההגעה',
            'admin_addon_purchase' => 'תוספת נרכשה: ' . config('addons.products.wa_pro.title') . ' · ₪129.00',
        ];
        foreach ($expect as $key => $subject) {
            $this->assertSame($subject, MailCatalog::make($key)->mailSubject(), $key);
        }
    }

    public function test_prices_in_copy_come_from_config(): void
    {
        config(['addons.products.wa_pro.price' => 141, 'addons.products.wa_topup.price' => 67, 'addons.products.seating_ai_topup.price' => 23]);
        $receipt = MailCatalog::make('addon_receipt', 'wa_pro')->render();
        $this->assertStringContainsString('₪141.00', $receipt);
        $quota = MailCatalog::make('quota_alert', '100')->render();
        $this->assertStringContainsString('₪67', $quota);
        $this->assertStringContainsString('₪141', $quota);
        $this->assertStringContainsString('₪23', MailCatalog::make('ai_runs_low')->render());
    }

    public function test_palette_pairs_meet_contrast_minimums_in_light_and_dark(): void
    {
        foreach (MailTheme::pairs() as [$label, $fg, $bg, $min]) {
            $this->assertGreaterThanOrEqual($min, MailTheme::contrast($fg, $bg), sprintf('%s %s on %s', $label, $fg, $bg));
        }
    }

    public function test_policy_quiet_hours_shabbat_and_exemptions(): void
    {
        config(['emails.policy.enabled' => true]);
        $p = new MailPolicy();
        $tz = 'Asia/Jerusalem';
        // Monday 22:30 → next day 08:00
        $d = $p->decide(MailPolicy::ACTIVITY, 'a@example.com', Carbon::parse('2026-11-30 22:30', $tz));
        $this->assertSame('defer', $d['action']);
        $this->assertSame('2026-12-01 08:00', $d['send_at']->copy()->setTimezone($tz)->format('Y-m-d H:i'));
        // Friday 17:00 activity → Saturday 20:00
        $d = $p->decide(MailPolicy::ACTIVITY, 'a@example.com', Carbon::parse('2026-12-04 17:00', $tz));
        $this->assertSame('2026-12-05 20:00', $d['send_at']->copy()->setTimezone($tz)->format('Y-m-d H:i'));
        // Friday 17:00 reminder → no Shabbat hold
        $this->assertSame('send', $p->decide(MailPolicy::REMINDER, 'a@example.com', Carbon::parse('2026-12-04 17:00', $tz))['action']);
        // Urgent / transactional / admin at 23:30 → immediately
        foreach ([MailPolicy::URGENT, MailPolicy::TRANSACTIONAL, MailPolicy::ADMIN] as $cat) {
            $this->assertSame('send', $p->decide($cat, 'a@example.com', Carbon::parse('2026-11-30 23:30', $tz))['action'], $cat);
        }
        // Daytime → send
        $this->assertSame('send', $p->decide(MailPolicy::DIGEST, 'fresh-' . uniqid() . '@example.com', Carbon::parse('2026-11-30 10:00', $tz))['action']);
        // Policy flag off → everything immediate
        config(['emails.policy.enabled' => false]);
        $this->assertSame('send', $p->decide(MailPolicy::ACTIVITY, 'a@example.com', Carbon::parse('2026-11-30 23:30', $tz))['action']);
    }

    public function test_daily_cap_skips_extra_digests_but_never_reminders(): void
    {
        config(['emails.policy.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00', 'Asia/Jerusalem'));
        Mail::fake();
        $to = 'cap-' . uniqid() . '@example.com';
        $svc = new MailService();
        $svc->send($to, MailEnum::RSVP_DIGEST, MailCatalog::sample('rsvp_digest'), ['dedupe' => 'cap-a-' . $to]);
        $svc->send($to, MailEnum::SETUP_NUDGE, MailCatalog::sample('setup_nudge'), ['dedupe' => 'cap-b-' . $to]);
        $svc->send($to, MailEnum::EVENT_STARTS_IN_1_DAY, MailCatalog::sample('event_tomorrow'));
        $this->assertSame(1, $this->mailed(\App\Mail\V2\RsvpDigestMail::class));
        $this->assertSame(0, $this->mailed(\App\Mail\V2\SetupNudgeMail::class));
        $this->assertSame(1, $this->mailed(\App\Mail\EventStartNotificationSecondMail::class));
        $this->assertSame(1, MailDelivery::where('recipient', $to)->where('status', 'skipped_cap')->count());
    }

    public function test_deferred_email_is_sent_later_by_the_command(): void
    {
        config(['emails.policy.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 23:00', 'Asia/Jerusalem'));
        Mail::fake();
        $to = 'defer-' . uniqid() . '@example.com';
        (new MailService())->send($to, MailEnum::SEND_SUMMARY, MailCatalog::sample('send_summary'), ['dedupe' => 'defer-' . $to]);
        $this->assertSame(0, $this->mailed(SendSummaryMail::class));
        $row = MailDelivery::where('recipient', $to)->firstOrFail();
        $this->assertSame('deferred', $row->status);

        Carbon::setTestNow(Carbon::parse('2026-12-01 07:59', 'Asia/Jerusalem'));
        Artisan::call('emails:send-deferred');
        $this->assertSame(0, $this->mailed(SendSummaryMail::class));
        Carbon::setTestNow(Carbon::parse('2026-12-01 08:01', 'Asia/Jerusalem'));
        Artisan::call('emails:send-deferred');
        $this->assertSame(1, $this->mailed(SendSummaryMail::class));
        $this->assertSame('sent', $row->refresh()->status);
        Artisan::call('emails:send-deferred');
        $this->assertSame(1, $this->mailed(SendSummaryMail::class));
    }

    public function test_new_emails_are_skipped_when_the_flag_is_off_and_legacy_still_sent(): void
    {
        Mail::fake();
        config(['emails.v2_enabled' => false]);
        (new MailService())->send('x@example.com', MailEnum::WELCOME_GOOGLE, ['first_name' => 'X', 'order_url' => 'http://client.test/order']);
        (new MailService())->send('x@example.com', MailEnum::CONTACT_CONFIRMATION, ['first_name' => 'X']);
        $this->assertSame(0, $this->mailed(WelcomeGoogleMail::class));
        $this->assertSame(1, $this->mailed(\App\Mail\ContactConfirmationMail::class));

        config(['emails.v2_enabled' => true]);
        (new MailService())->send('x@example.com', MailEnum::WELCOME_GOOGLE, ['first_name' => 'X', 'order_url' => 'http://client.test/order']);
        $this->assertSame(1, $this->mailed(WelcomeGoogleMail::class));
    }

    public function test_addon_receipt_and_admin_alert_are_sent_once_per_purchase(): void
    {
        Mail::fake();
        config(['addons.enabled' => true, 'addons.driver' => 'mock', 'addons.mock_dev_endpoints' => true, 'whatsapp_messaging.enabled' => true]);
        $this->makeHost('mailaddon');
        $uid = $this->api('POST', '/addons/checkout', ['product' => 'wa_pro'])->assertStatus(201)->json('data.purchase.uid');
        $this->api('POST', "/addons/purchases/{$uid}/dev-complete", ['outcome' => 'success'])->assertOk();
        $this->api('POST', "/addons/purchases/{$uid}/dev-complete", ['outcome' => 'success'])->assertOk();
        $this->assertSame(1, $this->mailed(AddonReceiptMail::class));
        $this->assertSame(1, $this->mailed(AdminAddonPurchaseMail::class));
        Mail::assertQueued(AddonReceiptMail::class, fn ($m) => $m->hasTo($this->user->email) && $m->mail_data['amount'] == config('addons.products.wa_pro.price'));
        Mail::assertQueued(AdminAddonPurchaseMail::class, fn ($m) => $m->hasTo(MailService::SYSTEM_EMAILS));
    }

    public function test_addon_failed_email_after_cancel(): void
    {
        Mail::fake();
        config(['addons.enabled' => true, 'addons.driver' => 'mock', 'addons.mock_dev_endpoints' => true, 'whatsapp_messaging.enabled' => true]);
        $this->makeHost('mailaddonf');
        $uid = $this->api('POST', '/addons/checkout', ['product' => 'wa_topup'])->assertStatus(201)->json('data.purchase.uid');
        $this->api('POST', "/addons/purchases/{$uid}/dev-complete", ['outcome' => 'cancel'])->assertOk();
        $this->assertSame(1, $this->mailed(AddonFailedMail::class));
        $this->assertSame(0, $this->mailed(AddonReceiptMail::class));
    }

    public function test_password_change_and_whatsapp_ready_and_hub_published_triggers(): void
    {
        Mail::fake();
        config(['whatsapp_messaging.enabled' => true]);
        $this->makeHost('mailtrig');
        $this->assertSame(0, $this->mailed(PasswordChangedMail::class), 'new user ≠ password change');
        $this->user->password = 'NewPassword2';
        $this->user->save();
        $this->assertSame(1, $this->mailed(PasswordChangedMail::class));

        MessagingContext::settings($this->event->id);
        MessagingContext::settings($this->event->id);
        $this->assertSame(1, $this->mailed(WhatsAppReadyMail::class));

        $hub = EventHub::create(['event_id' => $this->event->id, 'slug' => 'mail-' . uniqid(), 'is_published' => false, 'hub_config' => []]);
        $this->assertSame(0, $this->mailed(HubPublishedMail::class));
        $hub->update(['is_published' => true]);
        $hub->update(['is_published' => false]);
        $hub->update(['is_published' => true]);
        $this->assertSame(1, $this->mailed(HubPublishedMail::class));
    }

    public function test_send_finished_summary_or_problem(): void
    {
        Mail::fake();
        $this->makeHost('mailsend');
        $mk = function (int $ok, int $failed) {
            $send = WhatsAppSend::create(['event_id' => $this->event->id, 'user_id' => $this->user->id, 'source' => 'manual', 'title' => 'הזמנה', 'body' => 'x', 'status' => 'sending']);
            for ($i = 0; $i < $ok + $failed; $i++) {
                $g = $this->guest('G' . $i);
                WhatsAppSendRecipient::create(['send_id' => $send->id, 'event_id' => $this->event->id, 'guest_id' => $g->id, 'status' => $i < $ok ? 'sent' : 'failed', 'channel' => 'whatsapp', 'error' => $i < $ok ? null : 'bad number']);
            }
            $send->update(['status' => $failed ? 'partial' : 'sent']);
        };
        $mk(9, 1);
        $this->assertSame(1, $this->mailed(SendSummaryMail::class));
        $this->assertSame(0, $this->mailed(SendProblemMail::class));
        $mk(2, 3);
        $this->assertSame(1, $this->mailed(SendProblemMail::class));
        $this->assertSame(1, $this->mailed(\App\Mail\V2\AdminProviderAlertMail::class));
    }

    public function test_kernel_schedules_the_right_commands(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");
        $this->assertStringContainsString('event:notification-5-days', $events);
        $this->assertStringNotContainsString("event:notification-5-day'", $events);
        $this->assertStringContainsString('event:disable', $events);
        $this->assertStringContainsString('emails:send-deferred', $events);
        $this->assertStringContainsString('emails:daily', $events);
        $this->assertStringContainsString('emails:heads-up', $events);
        $this->assertArrayHasKey('event:notification-5-days', Artisan::all());
        foreach (preg_split('/\n/', $events) as $line) {
            if (preg_match("/artisan'? '?([a-z0-9:\-]+)/", $line, $m)) {
                $this->assertArrayHasKey($m[1], Artisan::all(), 'scheduled command does not exist: ' . $m[1]);
            }
        }
    }

    public function test_retention_warning_and_disable_are_aligned_to_180_and_365_days(): void
    {
        $now = Carbon::parse('2026-11-30 12:00', 'UTC');
        $this->assertSame(180, RetentionService::days(SubscriptionEnum::CLASSIC_ID));
        $this->assertSame(365, RetentionService::days(SubscriptionEnum::PREMIUM_ID));
        $this->assertNull(RetentionService::days(SubscriptionEnum::TRIAL_ID));
        $ago = fn (int $d) => $now->copy()->subDays($d)->setTime(10, 0);
        $this->assertTrue(RetentionService::shouldWarn($ago(177), SubscriptionEnum::CLASSIC_ID, $now));
        $this->assertFalse(RetentionService::shouldWarn($ago(11), SubscriptionEnum::CLASSIC_ID, $now), 'legacy 11-day rule is gone');
        $this->assertTrue(RetentionService::shouldWarn($ago(362), SubscriptionEnum::PREMIUM_ID, $now));
        $this->assertFalse(RetentionService::shouldDisable($ago(179), SubscriptionEnum::CLASSIC_ID, $now));
        $this->assertTrue(RetentionService::shouldDisable($ago(180), SubscriptionEnum::CLASSIC_ID, $now));
        $this->assertTrue(RetentionService::shouldDisable($ago(185), SubscriptionEnum::CLASSIC_ID, $now), 'catch-up window');
        $this->assertFalse(RetentionService::shouldDisable($ago(400), SubscriptionEnum::CLASSIC_ID, $now), 'no mass backfill');
        $this->assertFalse(RetentionService::shouldDisable($ago(14), SubscriptionEnum::CLASSIC_ID, $now), 'legacy 14-day rule is gone');
        $this->assertTrue(RetentionService::shouldDisable($ago(365), SubscriptionEnum::PREMIUM_ID, $now));
        $this->assertFalse(RetentionService::shouldDisable($ago(48), SubscriptionEnum::TRIAL_ID, $now));
        config(['emails.retention.protected_event_ids' => [6, 520]]);
        $this->assertTrue(RetentionService::isProtected(6));
        $this->assertTrue(RetentionService::isProtected(520));
        $this->assertFalse(RetentionService::isProtected(7));
        // F3 email: deactivation date = finished + 180 days, shown in the email
        $until = RetentionService::until($ago(177), SubscriptionEnum::CLASSIC_ID);
        $this->assertSame($ago(177)->addDays(180)->timezone('Asia/Jerusalem')->format('Y-m-d'), $until->format('Y-m-d'));
    }

    public function test_legacy_order_failed_shows_the_order_price(): void
    {
        config(['emails.v2_enabled' => false]);
        $this->assertStringContainsString('₪349.00', MailCatalog::make('order_failed')->render());
        config(['emails.v2_enabled' => true]);
        $this->assertStringContainsString('₪349.00', MailCatalog::make('order_failed')->render());
    }

    public function test_preview_route_is_dev_only(): void
    {
        config(['emails.preview_enabled' => true]);
        $this->get('/dev/emails')->assertOk()->assertSee('addon_receipt');
        $this->get('/dev/emails/order_confirmed')->assertOk()->assertSee('hello@snapshare-live.com');
        $this->get('/dev/emails/nope')->assertNotFound();
        config(['emails.preview_enabled' => false]);
        $this->get('/dev/emails')->assertNotFound();
    }

    public function test_v2_defaults_off_on_a_local_machine_wired_to_a_real_mailer(): void
    {
        $saved = [$_SERVER['MAIL_MAILER'] ?? null, $_SERVER['APP_ENV'] ?? null, $_SERVER['EMAILS_V2_ENABLED'] ?? null];
        $_SERVER['APP_ENV'] = 'local';
        unset($_SERVER['EMAILS_V2_ENABLED']);
        try {
            $_SERVER['MAIL_MAILER'] = 'ses';
            $this->assertFalse((require config_path('emails.php'))['v2_enabled'], 'local + SES must not auto-enable new emails');
            $_SERVER['MAIL_MAILER'] = 'log';
            $this->assertTrue((require config_path('emails.php'))['v2_enabled']);
            $_SERVER['APP_ENV'] = 'production';
            $this->assertFalse((require config_path('emails.php'))['v2_enabled']);
            $this->assertFalse((require config_path('emails.php'))['preview_enabled']);
        } finally {
            foreach (['MAIL_MAILER', 'APP_ENV', 'EMAILS_V2_ENABLED'] as $i => $k) {
                if ($saved[$i] === null) {
                    unset($_SERVER[$k]);
                } else {
                    $_SERVER[$k] = $saved[$i];
                }
            }
        }
    }

    public function test_admin_recipient_stays_hardcoded(): void
    {
        $this->assertSame('gal.blacky@gmail.com', MailService::SYSTEM_EMAILS);
        $this->assertNull(config('mail.admin_to'));
        $this->assertSame('hello@snapshare-live.com', config('mail.reply_to.address'));
    }
}
