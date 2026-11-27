<x-mail.layout :subject="$mail_subject" preheader="שתפו את הקישור או את קוד ה־QR." label="עמוד האירוע" reason="קיבלתם את המייל הזה כי פרסמתם את עמוד האירוע שלכם.">
<x-slot:hero><x-mail.hero kicker="פורסם" title="עמוד האירוע שלכם באוויר">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p>העמוד פורסם. כל מי שיש לו את הקישור יכול לראות אותו.</x-mail.p>
<x-mail.details :rows="[['קישור', $hub_url ?? '', true]]" />
<x-mail.button :href="$hub_url" label="פתיחת עמוד האירוע" />
<x-mail.steps title="מה עכשיו" :items="[['בדקו בנייד', 'רוב האורחים יפתחו את העמוד בטלפון.'], ['שתפו', 'שלחו את הקישור או הדפיסו את קוד ה־QR.']]" />
<x-mail.notice kind="info" title="אפשר לעדכן בכל רגע">שינויים בעמוד מופיעים מיד אצל כל האורחים.</x-mail.notice>
</x-mail.layout>
