<x-mail.layout :subject="$mail_subject" preheader="כל אורח מקבל קישור אישי עם מפה." label="סידור ישיבה" reason="קיבלתם את המייל הזה כי סידור הישיבה באירוע שלכם הושלם.">
<x-slot:hero><x-mail.hero kicker="הישיבה מוכנה" title="שלחו לאורחים את השולחן שלהם">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.stats :items="[[number_format((int) ($assigned ?? 0)), 'אורחים משובצים', 't-ok'], [number_format((int) ($tables ?? 0)), 'שולחנות', 't-ink']]" />
<x-mail.p>כל אורח יכול לקבל בוואטסאפ קישור אישי שמראה את השולחן שלו ואת המיקום שלו במפת האולם.</x-mail.p>
<x-mail.button :href="$compose_url" label="שליחת השולחנות לאורחים" />
<x-mail.notice kind="ok" title="הכול בשליטתכם">אפשר לתזמן את ההודעה, ולשנות או לבטל אותה עד שהיא יוצאת.</x-mail.notice>
</x-mail.layout>
