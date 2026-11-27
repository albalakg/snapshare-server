@php use App\Services\Mail\MailV2; $days = (int) ($days_remaining ?? 3); @endphp
<x-mail.layout :subject="$mail_subject ?? ''" preheader="הורידו אותם עכשיו — אחרי המחיקה אין דרך לשחזר." label="התראה לפני מחיקה" reason="קיבלתם את המייל הזה כי תקופת השמירה של האירוע שלכם מסתיימת.">
<x-slot:hero><x-mail.hero kicker="נדרשת פעולה" :title="'עוד ' . $days . ' ימים הקבצים יימחקו'" glyph="!" tone="warn">{{ $event->name }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.details title="פרטי האירוע" :rows="[['אירוע', $event->name], ['ימים להשבתה', (string) $days, true], ['תאריך השבתה', MailV2::date($deactivation_date), true]]" />
<x-mail.p>תקופת השמירה של החבילה מסתיימת. אחרי ההשבתה אי אפשר להיכנס לעמוד האירוע, וכל הקבצים נמחקים לצמיתות.</x-mail.p>
<x-mail.button :href="$download_url" label="להורדת הקבצים" />
<x-mail.notice kind="warn" title="אין שחזור אחרי המחיקה">מומלץ להוריד את כל הקבצים כבר עכשיו.</x-mail.notice>
</x-mail.layout>
