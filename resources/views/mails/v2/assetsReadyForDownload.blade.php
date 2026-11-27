<x-mail.layout :subject="$mail_subject ?? ''" preheader="כל התמונות והסרטונים, באיכות המקורית." label="הקבצים מוכנים" reason="קיבלתם את המייל הזה כי ביקשתם להוריד את קבצי האירוע.">
<x-slot:hero><x-mail.hero kicker="ההורדה מוכנה" title="הקבצים מוכנים להורדה">{{ $event->name }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.details title="פרטי ההורדה" :rows="[['אירוע', $event->name], ['מספר קבצים', number_format((int) ($total_assets ?? 0)), true], ['איכות', 'מקורית מלאה']]" />
<x-mail.button :href="$download_url" label="הורדת הקבצים" />
<x-mail.notice kind="info" title="נתקלתם בבעיה?">אם ההורדה לא מתחילה או נקטעת, כתבו לנו ונעזור.</x-mail.notice>
</x-mail.layout>
