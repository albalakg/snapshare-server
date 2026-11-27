<x-mail.layout :subject="$mail_subject ?? ''" preheader="הקישור תקף לשעה אחת." label="איפוס סיסמה" reason="קיבלתם את המייל הזה כי התבקש איפוס סיסמה לחשבון שלכם ב־SnapShare.">
<x-slot:hero><x-mail.hero kicker="אבטחת החשבון" title="איפוס סיסמה" glyph="">הקישור תקף לשעה אחת ולשימוש חד־פעמי.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p>קיבלנו בקשה לאפס את הסיסמה לחשבון שלכם. לחצו על הכפתור כדי לבחור סיסמה חדשה. אחרי האיפוס תועברו למסך ההתחברות.</x-mail.p>
<x-mail.button :href="$reset_url" label="בחירת סיסמה חדשה" />
<x-mail.notice kind="warn" title="לא ביקשתם?">אפשר להתעלם מהמייל, והסיסמה לא תשתנה. אם זה חוזר, כתבו לנו.</x-mail.notice>
</x-mail.layout>
