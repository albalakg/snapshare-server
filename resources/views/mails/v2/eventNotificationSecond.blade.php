@php use App\Services\Mail\MailV2; @endphp
<x-mail.layout :subject="$mail_subject ?? ''" preheader="רשימת בדיקה קצרה לפני שהאורחים מתחילים לשתף." label="תזכורת לפני האירוע" reason="קיבלתם את המייל הזה כי יש לכם אירוע ב־SnapShare שמתקיים מחר.">
<x-slot:hero><x-mail.hero kicker="עוד יום אחד" title="מחר זה קורה" glyph="★">{{ $event->name }} — {{ MailV2::date($event->starts_at, 'd/m/Y') }} בשעה {{ MailV2::date($event->starts_at, 'H:i') }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p :mb="6">רגע לפני שמתחילים, כדאי לעבור על כמה דברים:</x-mail.p>
<x-mail.checklist :items="$checklist ?? [
    [true, 'האירוע מסומן ״מוכן״'],
    [false, 'קוד ה־QR מודפס ומוכן באולם'],
    [false, 'האורחים קיבלו את הקישור לאירוע'],
]" />
<x-mail.button :href="$event_url" label="פתיחת האירוע" />
<x-mail.notice kind="info" title="האלבום נפתח אוטומטית">האלבום ייפתח לאורחים 8 שעות לפני תחילת האירוע, ואין צורך ללחוץ על כלום. תוכלו לעקוב אחרי ההעלאות בזמן אמת.</x-mail.notice>
<x-mail.p :mb="0">מאחלים לכם אירוע מושלם!</x-mail.p>
</x-mail.layout>
