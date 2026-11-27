@php use App\Services\Mail\MailV2; @endphp
<x-mail.layout :subject="$mail_subject" preheader="אפשר להמשיך לשבץ ידנית בכל מקרה." label="סידור ישיבה" reason="קיבלתם את המייל הזה כי השתמשתם בהצעות AI לסידור הישיבה באירוע שלכם.">
<x-slot:hero><x-mail.hero kicker="ריצות AI" :title="((int) ($runs_left ?? 0)) === 0 ? 'נגמרו ריצות ה־AI' : 'נשארה ריצת AI אחת'" glyph="">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p>השיבוץ הידני תמיד זמין. אם תרצו עוד הצעות AI, אפשר להוסיף {{ (int) config('addons.products.seating_ai_topup.runs', 10) }} ריצות ב־{{ MailV2::addonPrice('seating_ai_topup') }}.</x-mail.p>
<x-mail.button :href="$seating_url" label="לסידור הישיבה" />
</x-mail.layout>
