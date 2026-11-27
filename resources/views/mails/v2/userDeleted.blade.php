<x-mail.layout :subject="$mail_subject ?? ''" preheader="כל הנתונים הוסרו מהמערכת. תודה שהייתם איתנו." label="מחיקת חשבון" reason="קיבלתם את המייל הזה כי החשבון שלכם ב־SnapShare נמחק.">
<x-slot:hero><x-mail.hero kicker="החשבון נמחק" title="החשבון שלכם נמחק">מחקנו את החשבון ואת כל הנתונים הקשורים אליו.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p>מחקנו את החשבון שלכם ואת כל הנתונים הקשורים אליו: פרטי משתמש, אירועים וקבצים.</x-mail.p>
<x-mail.details title="פרטי המחיקה" :rows="[['תאריך מחיקה', \App\Services\Mail\MailV2::date(now(), 'd/m/Y · H:i'), true]]" />
<x-mail.notice kind="info" title="שמירת מידע לפי חוק">חלק מהמידע (למשל חשבוניות) נשמר לתקופה מוגבלת לצרכים משפטיים ורגולטוריים.</x-mail.notice>
<x-mail.p :mb="0">נשמח לראות אתכם שוב באירוע הבא.</x-mail.p>
<x-mail.link :href="\App\Services\Mail\MailV2::client('/signup')" label="לפתיחת חשבון חדש">רוצים לחזור?</x-mail.link>
</x-mail.layout>
