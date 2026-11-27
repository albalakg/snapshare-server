@php use App\Services\Mail\MailV2; @endphp
<x-mail.layout :subject="$mail_subject ?? ''" preheader="האורחים יכולים להעלות תמונות וסרטונים כבר עכשיו." label="האלבום פתוח" reason="קיבלתם את המייל הזה כי האלבום של האירוע שלכם נפתח לאורחים.">
<x-slot:hero><x-mail.hero kicker="בשידור חי" title="האלבום פתוח לאורחים" glyph="●">{{ $event->name }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.details title="פרטי האירוע" :rows="[
    ['אירוע', $event->name],
    ['תאריך', MailV2::date($event->starts_at), true],
    ['שעת התחלה', MailV2::date($event->starts_at, 'H:i'), true],
    ['האלבום נסגר', MailV2::date($event->finished_at, 'd/m/Y · H:i'), true],
]" />
<x-mail.p>האורחים יכולים להתחיל להעלות תמונות וסרטונים. אפשר לעקוב אחרי ההעלאות בזמן אמת ולהסתיר תמונות לא רצויות.</x-mail.p>
<x-mail.button :href="$event_url" label="לצפייה בזמן אמת" />
<x-mail.notice kind="info" title="טיפ">הציבו את קוד ה־QR במקום בולט באולם — בכניסה, ליד הבר ועל השולחנות.</x-mail.notice>
</x-mail.layout>
