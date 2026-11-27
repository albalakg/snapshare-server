<x-mail.layout :subject="$mail_subject" :preheader="(int) ($failed ?? 0) . ' הודעות לא נשלחו.'" label="בעיה בשליחה" reason="קיבלתם את המייל הזה כי הייתה בעיה בהודעה ששלחתם לאורחים.">
<x-slot:hero><x-mail.hero kicker="נדרשת תשומת לב" title="חלק מההודעות לא נשלחו" glyph="!" tone="warn">{{ $send_title ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.details :rows="[['הודעה', $send_title ?? ''], ['נמענים', number_format((int) ($recipients ?? 0)), true], ['לא נשלחו', number_format((int) ($failed ?? 0)), true], ['סיבה עיקרית', $reason ?? null]]" />
<x-mail.p>אפשר לשלוח שוב רק למי שלא קיבל. הודעות שלא יצאו לא נספרות במכסה.</x-mail.p>
<x-mail.button :href="$queue_url" label="לפרטי השליחה" />
</x-mail.layout>
