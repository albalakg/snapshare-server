@php use App\Services\Mail\MailV2; @endphp
<x-mail.layout :subject="$mail_subject ?? ''" preheader="סמנו אותו ״מוכן״ כדי שהאורחים יוכלו להעלות." label="האירוע מחר" reason="קיבלתם את המייל הזה כי יש לכם אירוע ב־SnapShare שמתקיים מחר.">
<x-slot:hero><x-mail.hero kicker="נדרשת פעולה" title="האירוע מחר, והוא עדיין ממתין" glyph="!" tone="warn">{{ $event->name }} — {{ MailV2::date($event->starts_at, 'd/m/Y · H:i') }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p>האירוע שלכם מתקיים מחר, אבל הסטטוס עדיין ״ממתין״. בלי לסמן אותו ״מוכן״ האלבום לא ייפתח, והאורחים לא יוכלו להעלות תמונות.</x-mail.p>
<x-mail.button :href="$event_url" label="סימון האירוע כמוכן" />
<x-mail.notice kind="warn" title="התאריך לא נכון?">אפשר לשנות אותו כל עוד הסטטוס ״ממתין״. כבר סימנתם? אפשר להתעלם מהמייל.</x-mail.notice>
</x-mail.layout>
