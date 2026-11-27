<x-mail.layout :subject="$mail_subject ?? ''" :preheader="'נרשם דרך ' . ($method ?? '')" label="משתמש חדש" reason="התראה פנימית למנהלי SnapShare.">
<x-slot:hero><x-mail.hero kicker="הרשמה" :title="trim(($first_name ?? '') . ' ' . ($last_name ?? '')) ?: 'משתמש חדש'" glyph="+">נרשם עכשיו ל־SnapShare.</x-mail.hero></x-slot:hero>
<x-mail.details :rows="[['שם', trim(($first_name ?? '') . ' ' . ($last_name ?? ''))], ['אימייל', $email ?? '', true], ['אופן הרשמה', $method ?? ''], ['זמן', \App\Services\Mail\MailV2::date(now(), 'd/m/Y H:i'), true]]" />
</x-mail.layout>
