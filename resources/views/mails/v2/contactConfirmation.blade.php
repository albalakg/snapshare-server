<x-mail.layout :subject="$mail_subject ?? ''" preheader="נחזור אליכם בהקדם." label="פנייה התקבלה" reason="קיבלתם את המייל הזה כי פניתם אלינו דרך האתר.">
<x-slot:hero><x-mail.hero kicker="קיבלנו" title="הפנייה שלכם התקבלה">תודה שפניתם אלינו.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.steps title="מה קורה עכשיו" :items="[['אנחנו בודקים את הפנייה', 'כל פנייה נקראת על ידי אדם מהצוות.'], ['נחזור אליכם בהקדם', 'בתשובה למייל הזה.']]" />
<x-mail.p>רוצים להוסיף פרטים? פשוט השיבו למייל הזה.</x-mail.p>
</x-mail.layout>
