<x-mail.layout :subject="$mail_subject" :preheader="(string) ($error ?? '')" label="התראת ספק" reason="התראה פנימית למנהלי SnapShare.">
<x-slot:hero><x-mail.hero kicker="ספקים" :title="($provider ?? '') . ' מחזיר שגיאות'" glyph="!" tone="warn"></x-mail.hero></x-slot:hero>
<x-mail.details :rows="[['ספק', $provider ?? ''], ['שגיאה', $error ?? ''], ['כשלים בשליחה', (string) ($count ?? 0), true], ['שליחה', '#' . ($send_id ?? ''), true], ['אירוע', '#' . ($event_id ?? ''), true]]" />
</x-mail.layout>
