<x-mail.layout :subject="$mail_subject" preheader="החשבון מוכן. בואו נקים את האירוע הראשון." label="ברוכים הבאים" reason="קיבלתם את המייל הזה כי נרשמתם ל־SnapShare עם חשבון Google.">
<x-slot:hero><x-mail.hero kicker="ברוכים הבאים" title="החשבון שלכם מוכן">נרשמתם עם Google, אז אין צורך לאמת את הכתובת.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.steps title="שלושה צעדים לאירוע הראשון" :items="[['בחרו חבילה', 'ניסיון חינם או חבילה מלאה.'], ['פתחו עמוד אירוע', 'שם, תאריך ומראה.'], ['שתפו QR', 'האורחים מעלים תמונות בלי להתקין כלום.']]" />
<x-mail.button :href="$order_url ?? \App\Services\Mail\MailV2::client('/order')" label="בחירת חבילה" />
</x-mail.layout>
