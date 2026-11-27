@php use App\Services\Mail\MailV2; $full = (int) ($threshold ?? 80) >= 100; @endphp
<x-mail.layout :subject="$mail_subject" preheader="כך ממשיכים לשלוח הודעות לאורחים." label="מכסת הודעות" reason="קיבלתם את המייל הזה כי מכסת הודעות WhatsApp באירוע שלכם עומדת להיגמר.">
<x-slot:hero><x-mail.hero kicker="מכסת הודעות" :title="$full ? 'מכסת ההודעות נגמרה' : 'רוב המכסה כבר נוצלה'" glyph="!" tone="warn">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.stats :items="[[number_format((int) ($used ?? 0)), 'נשלחו', 't-ink'], [number_format(max(0, (int) ($capacity ?? 0) - (int) ($used ?? 0))), 'נותרו', $full ? 't-warn' : 't-gold']]" />
@if($full)
<x-mail.p>הודעות מתוזמנות שעוד לא יצאו ימתינו עד שתוסיפו מכסה — לא נמחק כלום.</x-mail.p>
@endif
<x-mail.notice kind="info" title="אפשרויות">תוספת של +{{ (int) config('whatsapp_messaging.pricing.topup.per_guest', 2) }} הודעות לכל אורח ב־{{ MailV2::addonPrice('wa_topup') }}, או WhatsApp Pro ב־{{ MailV2::addonPrice('wa_pro') }} ({{ (int) config('whatsapp_messaging.pricing.pro_addon.per_guest', 6) }} הודעות לכל אורח).</x-mail.notice>
<x-mail.button :href="$packages_url" label="הוספת הודעות" />
</x-mail.layout>
