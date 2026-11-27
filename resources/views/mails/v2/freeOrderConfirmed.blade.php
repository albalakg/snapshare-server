<x-mail.layout :subject="$mail_subject" :preheader="'חבילת ' . ($plan ?? '') . ' פעילה. כך מתחילים.'" label="האירוע נוצר" reason="קיבלתם את המייל הזה כי פתחתם אירוע ב־SnapShare.">
<x-slot:hero><x-mail.hero kicker="האירוע נוצר" title="האירוע שלכם מוכן להתחיל">פתחנו לכם אירוע בחבילת {{ $plan ?? '' }}, בלי תשלום.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.details title="החבילה שלכם" :rows="[['מספר הזמנה', '#' . ($order_id ?? ''), true], ['חבילה', $plan ?? ''], ['קבצים לאירוע', isset($files_allowed) ? number_format((int) $files_allowed) : null, true], ['שמירת הקבצים אחרי האירוע', $storage_text ?? null]]" />
<x-mail.button :href="$event_url" label="כניסה לניהול האירוע" />
<x-mail.steps title="הצעדים הבאים" :items="[['השלימו את פרטי האירוע', 'שם, תאריך ושעה.'], ['שתפו את קוד ה־QR', 'האורחים סורקים ומעלים תמונות.']]" />
@if(!empty($is_trial))
<x-mail.notice kind="info" title="צריכים יותר?">אפשר לשדרג לחבילה מלאה בכל רגע לפני האירוע.</x-mail.notice>
@endif
</x-mail.layout>
