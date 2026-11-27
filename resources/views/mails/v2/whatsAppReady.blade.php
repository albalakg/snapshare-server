<x-mail.layout :subject="$mail_subject" preheader="תבניות בעברית, תזמון ושליטה מלאה." label="הודעות לאורחים" reason="קיבלתם את המייל הזה כי פתחתם את מרכז ההודעות באירוע שלכם.">
<x-slot:hero><x-mail.hero kicker="WhatsApp" title="מרכז ההודעות שלכם מוכן">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.steps title="איך מתחילים" :items="[['אשרו את תבניות ההודעה', 'Meta בודקת כל תבנית לפני השליחה הראשונה.'], ['הוסיפו אורחים עם טלפון', 'ייבוא מקובץ או הוספה ידנית.'], ['שלחו או תזמנו', 'בחבילה שלכם: ' . (int) ($per_guest ?? 0) . ' הודעות לכל אורח.']]" />
<x-mail.button :href="$messaging_url" label="למרכז ההודעות" />
<x-mail.notice kind="ok" title="הכול בשליטתכם">אפשר לתזמן הודעה, לשנות או לבטל אותה בכל רגע כל עוד לא נשלחה.</x-mail.notice>
</x-mail.layout>
