@php use App\Services\Mail\MailV2; @endphp
<x-mail.layout :subject="$mail_subject ?? ''" preheader="זה הזמן לוודא שהכול מוכן." label="תזכורת לפני האירוע" reason="קיבלתם את המייל הזה כי יש לכם אירוע ב־SnapShare בעוד 5 ימים.">
<x-slot:hero><x-mail.hero kicker="עוד 5 ימים" title="האירוע שלכם מתקרב" glyph="★">{{ $event->name }} — {{ MailV2::date($event->starts_at, 'd/m/Y · H:i') }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p>בעוד חמישה ימים האירוע שלכם יוצא לדרך. הנה מה שכדאי לבדוק עכשיו:</x-mail.p>
<x-mail.steps :items="array_values(array_filter([
    ['פרטי האירוע', 'ודאו שהשם, התאריך והשעה נכונים.'],
    ['קוד ה־QR', 'הדפיסו כרטיסים או הכינו שלט לאולם.'],
    config('whatsapp_messaging.enabled') ? ['הזמנות ו־Save the Date', 'אפשר לתזמן הודעות לאורחים — ולשנות או לבטל עד רגע השליחה.'] : null,
    config('seating.enabled') ? ['סידור ישיבה', 'שבצו את האורחים שאישרו הגעה.'] : null,
]))" />
<x-mail.button :href="$event_url" label="פתיחת האירוע" />
<x-mail.notice kind="info" title="טיפ">אפשר גם להעלות תמונות מראש לאלבום, כדי שהאורחים יראו אותו מלא כבר מהרגע הראשון.</x-mail.notice>
</x-mail.layout>
