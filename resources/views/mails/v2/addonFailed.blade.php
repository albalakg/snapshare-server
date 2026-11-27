<x-mail.layout :subject="$mail_subject" preheader="לא חויבתם. אפשר לנסות שוב." label="בעיה בתשלום" reason="קיבלתם את המייל הזה כי ניסיתם לרכוש תוספת ב־SnapShare.">
<x-slot:hero><x-mail.hero kicker="התשלום לא הושלם" title="התשלום על התוספת לא הושלם" glyph="!" tone="warn">לא בוצע שינוי באירוע.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.details :rows="[['מוצר', $title ?? ''], ['מספר רכישה', $purchase_uid ?? '', true], ['אירוע', $event_name ?? ''], ['סיבה', $reason ?? null]]" />
<x-mail.button :href="$retry_url" label="ניסיון חוזר" />
<x-mail.notice kind="warn" title="ראיתם חיוב?">כתבו לנו עם מספר הרכישה ונבדוק מיד.</x-mail.notice>
</x-mail.layout>
