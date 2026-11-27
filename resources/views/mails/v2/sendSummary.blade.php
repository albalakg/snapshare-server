<x-mail.layout :subject="$mail_subject" :preheader="'נשלחו ' . (int) ($sent ?? 0) . ', ' . (int) ($failed ?? 0) . ' לא נשלחו.'" label="סיכום שליחה" reason="קיבלתם את המייל הזה כי שלחתם הודעה לאורחים.">
<x-slot:hero><x-mail.hero kicker="השליחה הסתיימה" title="ההודעה נשלחה">{{ $send_title ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.stats :items="[[number_format((int) ($sent ?? 0)), 'נשלחו', 't-ok'], [number_format((int) ($failed ?? 0)), 'לא נשלחו', 't-warn'], [number_format((int) ($skipped ?? 0)), 'דולגו', 't-gold']]" />
@if((int) ($sms ?? 0) > 0)
<x-mail.p :muted="true" :size="15">{{ (int) $sms }} מהן נשלחו ב־SMS לאורחים בלי WhatsApp.</x-mail.p>
@endif
@if((int) ($failed ?? 0) > 0)
<x-mail.notice kind="warn" title="חלק מהאורחים לא קיבלו">בדרך כלל זה מספר שגוי או מספר בלי WhatsApp. אפשר לתקן ולשלוח שוב רק להם.</x-mail.notice>
@endif
<x-mail.button :href="$analytics_url" label="לצפייה בפירוט" />
</x-mail.layout>
