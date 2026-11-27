<x-mail.layout :subject="$mail_subject" :preheader="number_format((int) ($count ?? 0)) . ' קבצים הועלו עד עכשיו.'" label="האלבום מתמלא" reason="קיבלתם את המייל הזה כי האורחים התחילו להעלות קבצים לאירוע שלכם.">
<x-slot:hero><x-mail.hero kicker="האלבום מתמלא" title="הקבצים הראשונים כבר כאן" glyph="●">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.stats :items="[[number_format((int) ($count ?? 0)), 'קבצים הועלו', 't-ink']]" />
<x-mail.p>האורחים כבר משתפים. אפשר לצפות בהעלאות בזמן אמת ולהסתיר תמונות לא רצויות.</x-mail.p>
<x-mail.button :href="$gallery_url" label="לצפייה באלבום" />
</x-mail.layout>
