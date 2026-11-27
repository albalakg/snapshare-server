@php
    use App\Services\Mail\MailV2;
    $name = is_object($subscription) ? ($subscription->name ?? '') : (string) ($subscription['name'] ?? $subscription ?? '');
    $files = is_object($subscription) ? ($subscription->files_allowed ?? null) : ($subscription['files_allowed'] ?? null);
    $hours = is_object($subscription) ? ($subscription->storage_time ?? null) : ($subscription['storage_time'] ?? null);
@endphp
<x-mail.layout :subject="$mail_subject ?? ''" preheader="הנה מה שנפתח לכם." label="שדרוג חבילה" reason="קיבלתם את המייל הזה כי שדרגתם חבילה ב־SnapShare.">
<x-slot:hero><x-mail.hero kicker="השדרוג הושלם" :title="'החבילה שודרגה ל' . $name">הכול כבר פעיל באירוע שלכם, בלי צורך לעשות דבר.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.details title="החבילה החדשה" :rows="[
    ['חבילה', $name],
    ['קבצים לאירוע', $files ? number_format((int) $files) : null, true],
    ['שמירת הקבצים אחרי האירוע', $hours ? (intdiv((int) $hours, 24) . ' ימים') : null],
    ['תאריך שדרוג', MailV2::date(now()), true],
]" />
<x-mail.button :href="$manage_url" label="לניהול האירוע" />
</x-mail.layout>
