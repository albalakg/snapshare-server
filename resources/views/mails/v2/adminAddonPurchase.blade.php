<x-mail.layout :subject="$mail_subject" :preheader="($user_email ?? '') . ' · אירוע #' . ($event_id ?? '')" label="רכישת תוספת" reason="התראה פנימית למנהלי SnapShare.">
<x-slot:hero><x-mail.hero kicker="הכנסה" :title="'תוספת נרכשה: ' . ($title ?? '')" glyph="₪"></x-mail.hero></x-slot:hero>
<x-mail.details :rows="[['מוצר', ($title ?? '') . ' (' . ($product ?? '') . ')'], ['כמות', (string) ($quantity ?? 1), true], ['סכום', \App\Services\Mail\MailV2::money($amount ?? 0), true], ['משתמש', $user_email ?? '', true], ['אירוע', '#' . ($event_id ?? ''), true], ['רכישה', $purchase_uid ?? '', true], ['ספק', $provider ?? '', true]]" />
</x-mail.layout>
