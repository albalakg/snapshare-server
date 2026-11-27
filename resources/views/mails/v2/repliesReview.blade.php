<x-mail.layout :subject="$mail_subject" preheader="ה־AI הציע סיווג — אתם מחליטים." label="תגובות אורחים" reason="קיבלתם את המייל הזה כי יש תגובות אורחים שממתינות לבדיקה שלכם.">
<x-slot:hero><x-mail.hero kicker="ממתינות לכם" :title="(int) ($count ?? 0) . ' תגובות לבדיקה'" glyph="">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.people :rows="$items ?? []" />
<x-mail.notice kind="info" title="ה־AI מציע, לא מחליט">שום תשובה לא משתנה אוטומטית — רק אחרי שתאשרו.</x-mail.notice>
<x-mail.button :href="$inbox_url" label="לתיבת ההודעות" />
</x-mail.layout>
