<x-mail.layout :subject="$mail_subject ?? ''" preheader="לחיצה אחת והחשבון שלכם פעיל." label="ברוכים הבאים" reason="קיבלתם את המייל הזה כי נרשמתם ל־SnapShare עם הכתובת הזו.">
<x-slot:hero><x-mail.hero kicker="ברוכים הבאים" title="עוד לחיצה אחת ואנחנו מוכנים">אמתו את כתובת המייל כדי להפעיל את החשבון ואת האירוע הראשון שלכם.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p>תודה שהצטרפתם ל־SnapShare. כדי להשלים את ההרשמה צריך לאמת שהכתובת הזו שלכם.</x-mail.p>
<x-mail.button :href="$verification_url" label="אימות כתובת המייל" />
<x-mail.notice kind="info" title="הקישור תקף ל־24 שעות">לא נרשמתם? אפשר להתעלם מהמייל, ושום חשבון לא יופעל.</x-mail.notice>
<x-mail.link :href="$order_url" label="בחירת חבילה">אחרי האימות:</x-mail.link>
</x-mail.layout>
