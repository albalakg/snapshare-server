<x-mail.layout :subject="$mail_subject" preheader="שלחו לאורחים בוואטסאפ, עם תזמון." label="Save the Date" reason="קיבלתם את המייל הזה כי הפעלתם Save the Date באירוע שלכם.">
<x-slot:hero><x-mail.hero kicker="מוכן לשליחה" title="ה־Save the Date שלכם מוכן">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p>הפעלתם את ה־Save the Date. אפשר לשלוח אותו לאורחים בוואטסאפ, עם קישור לעמוד האירוע.</x-mail.p>
<x-mail.button :href="$compose_url" label="לשליחה לאורחים" />
<x-mail.notice kind="ok" title="הכול בשליטתכם">אפשר לתזמן מראש, ולשנות או לבטל כל שליחה כל עוד היא לא יצאה.</x-mail.notice>
</x-mail.layout>
