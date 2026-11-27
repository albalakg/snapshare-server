<?php

/*
| Transactional email system v2 (master Blade layout, approved mock t141u).
|
| EMAILS_V2_ENABLED  — new layout + copy for the 18 existing mailables, and turns on the NEW emails
|                      (they only exist in the v2 layout). Default: on in local/testing/development, off in production.
|                      Safety: a local machine whose MAIL_MAILER is a real transport (ses/smtp/…) also defaults to OFF,
|                      so a dev box wired to SES never starts sending the new emails to real addresses by itself.
|                      Set EMAILS_V2_ENABLED=true explicitly to opt in.
| EMAILS_POLICY_*    — quiet hours / Shabbat / 1-per-day cap for non-urgent event notifications. Default on.
| EMAILS_PREVIEW_ENABLED — dev-only preview route (/dev/emails) and `php artisan emails:preview`. Never in production.
|
| Brand / footer / reply-to live in config/mail.php ('brand', 'reply_to'). Admin recipient stays MailService::SYSTEM_EMAILS (Gal t142u).
| No marketing emails and no guest emails (Gal t142u).
*/

$localish = in_array(env('APP_ENV', 'production'), ['local', 'testing', 'development'], true);
$realMailer = in_array(strtolower((string) env('MAIL_MAILER', 'smtp')), ['ses', 'ses-v2', 'smtp', 'sendmail', 'mailgun', 'postmark', 'resend', 'failover'], true);

return [
    'v2_enabled' => filter_var(env('EMAILS_V2_ENABLED', $localish && !$realMailer), FILTER_VALIDATE_BOOLEAN),

    'preview_enabled' => filter_var(env('EMAILS_PREVIEW_ENABLED', $localish), FILTER_VALIDATE_BOOLEAN),

    'policy' => [
        'enabled' => filter_var(env('EMAILS_POLICY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'timezone' => 'Asia/Jerusalem',
        // Non-urgent notifications are held outside [quiet_end, quiet_start).
        'quiet_start' => env('EMAILS_QUIET_START', '21:00'),
        'quiet_end' => env('EMAILS_QUIET_END', '08:00'),
        // Shabbat window (Israel time): Friday 16:00 → Saturday 20:00.
        'shabbat' => filter_var(env('EMAILS_SHABBAT_GUARD', true), FILTER_VALIDATE_BOOLEAN),
        'shabbat_start' => '16:00',
        'shabbat_end' => '20:00',
        // Max non-urgent notification emails per recipient per rolling 24h (reminders count but are never dropped).
        'daily_cap' => (int) env('EMAILS_DAILY_CAP', 1),
    ],

    // Thresholds / timings for automatic emails.
    'triggers' => [
        'deactivation_warning_days' => 3,           // F3: days before the retention end (subscriptions.storage_time)
        'setup_nudge_days' => [2, 7],               // C1: days after purchase
        'digest_hour' => '09:00',                   // I5 / C1 / H2 daily run (Israel time)
        'heads_up_minutes' => 60,                   // I3
        'heads_up_min_recipients' => 20,            // I3
        'quota_thresholds' => [80, 100],            // G3 (% of message capacity)
        'send_problem_ratio' => 0.3,                // I7: failed / recipients
        'table_links_days_before' => 3,             // H2
        'seating_nudge_days_before' => 10,          // H1
        'seating_nudge_min_confirmed' => 10,        // H1
        'replies_review_min' => 3,                  // I6
        'first_uploads_milestones' => [1, 50],      // E2: max 2 emails per event
    ],

    // Retention (Gal t142u): Classic 180 / Premium 365 days, from subscriptions.storage_time. See RetentionService.
    'retention' => [
        // event:disable also catches events up to N days past their retention day (missed scheduler runs) — never older.
        'catch_up_days' => (int) env('RETENTION_CATCH_UP_DAYS', 7),
        // Never disabled by event:disable. Local default protects the dev/test events 6 and 520.
        'protected_event_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('RETENTION_PROTECTED_EVENT_IDS', $localish ? '6,520' : ''))))),
    ],
];
