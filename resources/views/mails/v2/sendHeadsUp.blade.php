<x-mail.layout :subject="$mail_subject" preheader="עוד אפשר לשנות, לדחות או לבטל." label="שליחה מתוזמנת" reason="קיבלתם את המייל הזה כי תזמנתם הודעה לאורחים.">
<x-slot:hero><x-mail.hero kicker="בעוד שעה" title="הודעה עומדת לצאת לאורחים" glyph="!">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.details title="פרטי השליחה" :rows="[['הודעה', $send_title ?? ''], ['נמענים', number_format((int) ($recipients ?? 0)), true], ['מועד', $send_time ?? '', true]]" />
<x-mail.p>עוד אפשר לערוך, לדחות או לבטל את השליחה — עד הרגע שבו היא יוצאת.</x-mail.p>
<x-mail.button :href="$queue_url" label="ניהול השליחה" />
</x-mail.layout>
