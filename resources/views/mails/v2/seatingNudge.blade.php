<x-mail.layout :subject="$mail_subject" :preheader="(int) ($confirmed ?? 0) . ' אורחים אישרו. נשאר רק לשבץ.'" label="סידור ישיבה" reason="קיבלתם את המייל הזה כי האירוע שלכם מתקרב ויש אורחים שאישרו הגעה.">
<x-slot:hero><x-mail.hero kicker="סידור ישיבה" title="אפשר להתחיל לשבץ" glyph="">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.stats :items="[[number_format((int) ($confirmed ?? 0)), 'אישרו הגעה', 't-ok']]" />
<x-mail.p>אפשר לשבץ ידנית או לבקש הצעה מה־AI — אתם תמיד מאשרים לפני שמשהו משתנה.</x-mail.p>
<x-mail.button :href="$seating_url" label="לסידור הישיבה" />
</x-mail.layout>
