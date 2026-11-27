<x-mail.layout :subject="$mail_subject ?? ''" :preheader="mb_substr((string) ($text ?? ''), 0, 100)" label="פניית תמיכה" reason="התראה פנימית למנהלי SnapShare.">
<x-slot:hero><x-mail.hero :kicker="'פנייה #' . ($ticket_id ?? '')" :title="'פנייה חדשה מ' . ($full_name ?? '')" glyph="?"></x-mail.hero></x-slot:hero>
<x-mail.details :rows="[['מספר פנייה', (string) ($ticket_id ?? ''), true], ['שם', $full_name ?? ''], ['אימייל', $email ?? '', true]]" />
<x-mail.h2>תוכן הפנייה</x-mail.h2>
<x-mail.notice kind="info">{{ $text ?? '' }}</x-mail.notice>
<x-mail.button :href="'mailto:' . ($email ?? '')" label="מענה בדוא״ל" />
</x-mail.layout>
