<?php

namespace App\Services\Mail;

use App\Mail;
use App\Mail\V2;
use App\Models\Event;
use App\Models\Order;
use App\Models\Subscription;
use Carbon\Carbon;

/**
 * Registry of every SnapShare email (PLAN.md ids A1–J7). Used by MailService (category / new flag),
 * the dev preview (/dev/emails, `php artisan emails:preview`) and the tests (sample data).
 * Dropped by Gal (t142u): B5 abandoned checkout, F5 feedback — marketing.
 */
class MailCatalog
{
    /** key => [plan id, class, category, new] */
    public const ITEMS = [
        'user_signup' => ['A1', Mail\UserSignupMail::class, MailPolicy::TRANSACTIONAL, false],
        'welcome_google' => ['A2', V2\WelcomeGoogleMail::class, MailPolicy::TRANSACTIONAL, true],
        'forgot_password' => ['A3', Mail\ForgotPasswordMail::class, MailPolicy::TRANSACTIONAL, false],
        'password_changed' => ['A4', V2\PasswordChangedMail::class, MailPolicy::TRANSACTIONAL, true],
        'user_deleted' => ['A5', Mail\UserDeletedMail::class, MailPolicy::TRANSACTIONAL, false],
        'order_confirmed' => ['B1', Mail\OrderConfirmedMail::class, MailPolicy::TRANSACTIONAL, false],
        'order_failed' => ['B2', Mail\OrderFailedMail::class, MailPolicy::TRANSACTIONAL, false],
        'free_order_confirmed' => ['B3', V2\FreeOrderConfirmedMail::class, MailPolicy::TRANSACTIONAL, true],
        'subscription_upgraded' => ['B4', Mail\SubscriptionUpgradedMail::class, MailPolicy::TRANSACTIONAL, false],
        'setup_nudge' => ['C1', V2\SetupNudgeMail::class, MailPolicy::DIGEST, true],
        'hub_published' => ['C2', V2\HubPublishedMail::class, MailPolicy::ACTIVITY, true],
        'save_the_date_ready' => ['C3', V2\SaveTheDateReadyMail::class, MailPolicy::DIGEST, true],
        'event_pending' => ['D1', Mail\EventPendingStartNotificationMail::class, MailPolicy::URGENT, false],
        'event_in_5_days' => ['D2', Mail\EventStartNotificationFirstMail::class, MailPolicy::REMINDER, false],
        'event_tomorrow' => ['D3', Mail\EventStartNotificationSecondMail::class, MailPolicy::REMINDER, false],
        'event_started' => ['E1', Mail\EventStartedMail::class, MailPolicy::URGENT, false],
        'first_uploads' => ['E2', V2\FirstUploadsMail::class, MailPolicy::DIGEST, true],
        'event_finished' => ['F1', Mail\EventFinishedMail::class, MailPolicy::URGENT, false],
        'assets_ready' => ['F2', Mail\AssetsReadyForDownloadMail::class, MailPolicy::TRANSACTIONAL, false],
        'deactivation_warning' => ['F3', Mail\EventWarningBeforeDeactivationMail::class, MailPolicy::URGENT, false],
        'event_deactivated' => ['F4', Mail\EventDeactivateMail::class, MailPolicy::URGENT, false],
        'addon_receipt' => ['G1', V2\AddonReceiptMail::class, MailPolicy::TRANSACTIONAL, true],
        'addon_failed' => ['G2', V2\AddonFailedMail::class, MailPolicy::TRANSACTIONAL, true],
        'quota_alert' => ['G3', V2\QuotaAlertMail::class, MailPolicy::URGENT, true],
        'ai_runs_low' => ['G4', V2\AiRunsLowMail::class, MailPolicy::DIGEST, true],
        'seating_nudge' => ['H1', V2\SeatingNudgeMail::class, MailPolicy::DIGEST, true],
        'table_links_ready' => ['H2', V2\TableLinksReadyMail::class, MailPolicy::DIGEST, true],
        'whatsapp_ready' => ['I1', V2\WhatsAppReadyMail::class, MailPolicy::ACTIVITY, true],
        'template_reviewed' => ['I2', V2\TemplateReviewedMail::class, MailPolicy::ACTIVITY, true],
        'send_heads_up' => ['I3', V2\SendHeadsUpMail::class, MailPolicy::URGENT, true],
        'send_summary' => ['I4', V2\SendSummaryMail::class, MailPolicy::ACTIVITY, true],
        'rsvp_digest' => ['I5', V2\RsvpDigestMail::class, MailPolicy::DIGEST, true],
        'replies_review' => ['I6', V2\RepliesReviewMail::class, MailPolicy::DIGEST, true],
        'send_problem' => ['I7', V2\SendProblemMail::class, MailPolicy::URGENT, true],
        'application_error' => ['J1', Mail\ApplicationErrorMail::class, MailPolicy::ADMIN, false],
        'admin_new_user' => ['J2', Mail\NewUserSignupAdminMail::class, MailPolicy::ADMIN, false],
        'admin_support' => ['J3', Mail\NewSupportRequestAdminMail::class, MailPolicy::ADMIN, false],
        'contact_confirmation' => ['J4', Mail\ContactConfirmationMail::class, MailPolicy::TRANSACTIONAL, false],
        'admin_addon_purchase' => ['J5', V2\AdminAddonPurchaseMail::class, MailPolicy::ADMIN, true],
        'admin_payment_alert' => ['J6', V2\AdminPaymentAlertMail::class, MailPolicy::ADMIN, true],
        'admin_provider_alert' => ['J7', V2\AdminProviderAlertMail::class, MailPolicy::ADMIN, true],
    ];

    /** @return array{key: string, id: string, class: string, category: string, new: bool}|null */
    public static function meta(string $class): ?array
    {
        foreach (self::ITEMS as $key => [$id, $cls, $cat, $new]) {
            if (ltrim($class, '\\') === $cls) {
                return ['key' => $key, 'id' => $id, 'class' => $cls, 'category' => $cat, 'new' => $new];
            }
        }
        return null;
    }

    public static function get(string $key): ?array
    {
        if (!isset(self::ITEMS[$key])) {
            return null;
        }
        [$id, $cls, $cat, $new] = self::ITEMS[$key];
        return ['key' => $key, 'id' => $id, 'class' => $cls, 'category' => $cat, 'new' => $new];
    }

    public static function keys(): array
    {
        return array_keys(self::ITEMS);
    }

    /** Build a mailable with sample data (unsaved models only — nothing touches the DB). */
    public static function make(string $key, ?string $variant = null)
    {
        $item = self::get($key);
        return new $item['class'](self::sample($key, $variant));
    }

    /** Variants worth a separate screenshot / test. */
    public static function variants(string $key): array
    {
        return match ($key) {
            'addon_receipt' => ['wa_pro', 'wa_topup', 'seating_addon', 'seating_ai_topup'],
            'template_reviewed' => ['approved', 'rejected'],
            'quota_alert' => ['80', '100'],
            default => [null],
        };
    }

    public static function sample(string $key, ?string $variant = null): array
    {
        $tz = 'Asia/Jerusalem';
        $client = fn (string $p) => MailV2::client($p);
        $subscription = new Subscription();
        $subscription->forceFill(['id' => 2, 'name' => 'Classic', 'files_allowed' => 1500, 'storage_time' => 180 * 24, 'price' => 349]);
        $event = new Event();
        $event->forceFill([
            'id' => 4821, 'name' => 'החתונה של נועה ואיתי',
            'starts_at' => Carbon::parse('2026-12-17 19:30', $tz), 'date' => Carbon::parse('2026-12-17 19:30', $tz),
            'finished_at' => Carbon::parse('2026-12-18 02:00', $tz), 'assets_count' => 1240,
        ]);
        $order = new Order();
        $order->forceFill(['id' => 10482, 'price' => 349, 'created_at' => Carbon::parse('2026-11-27 10:14', $tz)]);
        $order->setRelation('subscription', $subscription);
        $eventUrl = $client('/event');
        $base = ['first_name' => 'נועה', 'event_name' => $event->name];

        return match ($key) {
            'user_signup' => ['first_name' => 'נועה', 'verification_url' => $client('/email-confirmation?token=sample'), 'order_url' => $client('/order')],
            'welcome_google' => ['first_name' => 'נועה', 'order_url' => $client('/order')],
            'forgot_password' => ['first_name' => 'נועה', 'reset_url' => $client('/reset-password?token=sample')],
            'password_changed' => ['first_name' => 'נועה', 'changed_at' => '27/11/2026 10:14'],
            'user_deleted', 'contact_confirmation' => ['first_name' => 'נועה'],
            'order_confirmed' => ['order' => $order, 'first_name' => 'נועה', 'event_url' => $eventUrl],
            'order_failed' => ['order' => $order, 'first_name' => 'נועה', 'failure_reason' => 'הכרטיס נדחה על ידי חברת האשראי', 'retry_url' => $client('/order')],
            'free_order_confirmed' => ['first_name' => 'נועה', 'order_id' => 10483, 'plan' => 'Trial', 'files_allowed' => 100, 'storage_text' => '48 שעות', 'is_trial' => true, 'event_url' => $eventUrl],
            'subscription_upgraded' => ['first_name' => 'נועה', 'subscription' => $subscription, 'manage_url' => $eventUrl],
            'setup_nudge' => $base + ['missing_count' => 2, 'checklist' => [[true, 'שם ותאריך האירוע'], [false, 'עמוד האורחים'], [false, 'הורדת קוד ה־QR']], 'event_url' => $eventUrl],
            'hub_published' => $base + ['hub_url' => $client('/h/noa-itay')],
            'save_the_date_ready' => $base + ['compose_url' => $client('/event/save-the-date')],
            'event_pending', 'event_in_5_days', 'event_tomorrow', 'event_started' => ['event' => $event, 'first_name' => 'נועה', 'event_url' => $eventUrl],
            'first_uploads' => $base + ['count' => 37, 'gallery_url' => $client('/event/gallery')],
            'event_finished' => ['event' => $event, 'first_name' => 'נועה', 'event_url' => $eventUrl, 'retention_until' => Carbon::parse('2027-06-16 02:00', $tz)],
            'assets_ready' => ['event' => $event, 'first_name' => 'נועה', 'total_assets' => 1240, 'download_url' => $client('/download/sample')],
            'deactivation_warning' => ['event' => $event, 'first_name' => 'נועה', 'download_url' => $client('/event/assets'), 'deactivation_date' => Carbon::now()->addDays(3)],
            'event_deactivated' => ['event' => $event, 'first_name' => 'נועה'],
            'addon_receipt' => self::addonSample($variant ?? 'wa_pro', $base, $client),
            'addon_failed' => $base + ['title' => config('addons.products.wa_pro.title'), 'purchase_uid' => 'AP-7Q2K9', 'reason' => 'התשלום בוטל', 'retry_url' => $client('/event/messages/packages')],
            'quota_alert' => $base + ['threshold' => (int) ($variant ?? 80), 'used' => ($variant ?? '80') === '100' ? 640 : 512, 'capacity' => 640, 'packages_url' => $client('/event/messages/packages')],
            'ai_runs_low' => $base + ['runs_left' => 1, 'seating_url' => $client('/event/seating')],
            'seating_nudge' => $base + ['confirmed' => 142, 'seating_url' => $client('/event/seating')],
            'table_links_ready' => $base + ['assigned' => 186, 'tables' => 19, 'compose_url' => $client('/event/messages/compose')],
            'whatsapp_ready' => $base + ['per_guest' => 2, 'messaging_url' => $client('/event/messages')],
            'template_reviewed' => $base + ['template_title' => 'הזמנה לאירוע', 'status' => $variant ?? 'approved', 'reason' => ($variant === 'rejected') ? 'הנוסח כולל קישור מקוצר.' : null, 'templates_url' => $client('/event/messages/templates')],
            'send_heads_up' => $base + ['send_title' => 'תזכורת לאישור הגעה', 'send_time' => '17/12/2026 18:00', 'recipients' => 118, 'queue_url' => $client('/event/messages/queue')],
            'send_summary' => $base + ['send_title' => 'הזמנה לאירוע', 'recipients' => 320, 'sent' => 309, 'failed' => 7, 'skipped' => 4, 'sms' => 12, 'analytics_url' => $client('/event/messages/analytics')],
            'rsvp_digest' => $base + ['new_count' => 23, 'answered' => 214, 'total' => 320, 'counts' => ['yes' => 168, 'no' => 31, 'maybe' => 15, 'pending' => 106], 'review_count' => 4, 'guests_url' => $client('/event/guests'),
                'recent' => [['מיכל כהן', '2 אורחים · לפני שעה', 'מגיעים', 't-ok'], ['יוסי לוי', 'לפני 3 שעות', 'לא מגיעים', 't-warn'], ['דנה אברהם', '3 אורחים · אתמול', 'אולי', 't-gold']]],
            'replies_review' => $base + ['count' => 4, 'inbox_url' => $client('/event/messages/inbox'),
                'items' => [['רון שפירא', '״נגיע אבל בלי הילדים״', 'מגיעים · 2', 't-ok'], ['אורית בן דוד', '״עדיין לא בטוחה״', 'אולי', 't-gold']]],
            'send_problem' => $base + ['send_title' => 'הזמנה לאירוע', 'recipients' => 320, 'failed' => 112, 'reason' => 'מספרי טלפון לא תקינים', 'queue_url' => $client('/event/messages/queue')],
            'application_error' => ['error_message' => 'SQLSTATE[HY000]: General error: sample', 'error_trace' => "#0 app/Services/Sample.php(42): sample()\n#1 {main}", 'request_url' => 'https://api.snapshare-live.com/api/sample', 'request_data' => ['id' => 1]],
            'admin_new_user' => ['first_name' => 'נועה', 'last_name' => 'כהן', 'email' => 'noa@example.com', 'method' => 'google'],
            'admin_support' => ['full_name' => 'נועה כהן', 'email' => 'noa@example.com', 'text' => 'שלום, איך משנים את תאריך האירוע?', 'ticket_id' => 311],
            'admin_addon_purchase' => ['title' => config('addons.products.wa_pro.title'), 'product' => 'wa_pro', 'amount' => (float) config('addons.products.wa_pro.price'), 'quantity' => 1, 'user_email' => 'noa@example.com', 'event_id' => 4821, 'purchase_uid' => 'AP-7Q2K9', 'provider' => 'payplus'],
            'admin_payment_alert' => ['kind' => 'order', 'id' => 10482, 'reason' => 'invalid hash', 'ip' => '203.0.113.7'],
            'admin_provider_alert' => ['provider' => 'meta', 'error' => '131026 Message undeliverable', 'count' => 112, 'send_id' => 77, 'event_id' => 4821],
            default => [],
        };
    }

    /** G1 variant data — titles and prices always come from config/addons.php. */
    public static function addonSample(string $product, array $base, ?callable $client = null): array
    {
        $client ??= fn (string $p) => MailV2::client($p);
        $cfg = config("addons.products.$product", []);
        $short = ['wa_pro' => 'WhatsApp Pro', 'wa_topup' => 'תוספת הודעות', 'seating_addon' => 'הושבה חכמה', 'seating_ai_topup' => 'סידורים חכמים'][$product] ?? ($cfg['title'] ?? '');
        $benefit = match ($product) {
            'wa_pro' => (int) config('whatsapp_messaging.pricing.pro_addon.per_guest', 6) . ' הודעות לכל אורח, בלי הגבלת אורחים.',
            'wa_topup' => 'עוד ' . (int) config('whatsapp_messaging.pricing.topup.per_guest', 2) . ' הודעות לכל אורח.',
            'seating_addon' => 'בונה ההושבה פתוח, כולל סידורים חכמים.',
            'seating_ai_topup' => 'עוד ' . (int) ($cfg['runs'] ?? 10) . ' סידורים חכמים.',
            default => (string) ($cfg['description'] ?? ''),
        };
        $cta = str_starts_with($product, 'seating') ? $client('/event/seating') : $client('/event/messages');
        return $base + [
            'product' => $product, 'title' => $cfg['title'] ?? $product, 'short_title' => $short, 'benefit' => $benefit,
            'quantity' => 1, 'amount' => (float) ($cfg['price'] ?? 0), 'purchase_uid' => 'AP-7Q2K9', 'cta_url' => $cta,
            'ai_runs_total' => $product === 'seating_ai_topup' ? 12 : null,
        ];
    }
}
