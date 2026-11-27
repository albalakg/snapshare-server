<x-mail.layout :subject="$mail_subject" :preheader="(string) ($reason ?? '')" label="התראת תשלום" reason="התראה פנימית למנהלי SnapShare.">
<x-slot:hero><x-mail.hero kicker="תשלומים" title="callback תשלום לא תקין" glyph="!" tone="warn"></x-mail.hero></x-slot:hero>
<x-mail.details :rows="[['סוג', $kind ?? ''], ['מזהה', (string) ($id ?? ''), true], ['סיבה', $reason ?? ''], ['IP', $ip ?? null, true], ['זמן', \App\Services\Mail\MailV2::date(now(), 'd/m/Y H:i:s'), true]]" />
</x-mail.layout>
