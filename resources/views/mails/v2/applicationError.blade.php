<x-mail.layout :subject="$mail_subject ?? ''" :preheader="mb_substr((string) ($error_message ?? ''), 0, 100)" label="התראת מערכת" reason="התראה פנימית למנהלי SnapShare.">
<x-slot:hero><x-mail.hero :kicker="'סביבה: ' . app()->environment()" title="שגיאת מערכת" glyph="!" tone="warn">{{ mb_substr((string) ($error_message ?? ''), 0, 160) }}</x-mail.hero></x-slot:hero>
<x-mail.details :rows="[['הודעה', $error_message ?? ''], ['כתובת בקשה', $request_url ?? null, true], ['זמן', \App\Services\Mail\MailV2::date(now(), 'd/m/Y H:i:s'), true]]" />
<x-mail.h2>Stack trace</x-mail.h2>
<x-mail.code>{{ $error_trace ?? '' }}</x-mail.code>
@if(!empty($request_data))
<x-mail.h2>נתוני בקשה (מסוננים)</x-mail.h2>
<x-mail.code>{{ print_r($request_data, true) }}</x-mail.code>
@endif
</x-mail.layout>
