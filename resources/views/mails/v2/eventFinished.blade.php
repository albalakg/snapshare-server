@php use App\Services\Mail\MailV2; $count = (int) ($event->assets_count ?? 0); @endphp
<x-mail.layout :subject="$mail_subject ?? ''" :preheader="number_format($count) . ' קבצים מחכים לכם להורדה.'" label="סיכום האירוע" reason="קיבלתם את המייל הזה כי האירוע שלכם ב־SnapShare הסתיים.">
<x-slot:hero><x-mail.hero kicker="האירוע הסתיים" title="תודה על אירוע מדהים!">{{ $event->name }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.stats :items="[[number_format($count), 'קבצים באלבום', 't-ink']]" />
<x-mail.p>האירוע הסתיים, והאלבום מלא. כל התמונות והסרטונים מחכים לכם באיכות המקורית.</x-mail.p>
@if(!empty($retention_until))
<x-mail.details :rows="[['הקבצים זמינים עד', MailV2::date($retention_until), true]]" />
@endif
<x-mail.button :href="$event_url" label="להורדת הקבצים" />
<x-mail.notice kind="warn" title="כדאי להוריד כבר עכשיו">@if(!empty($retention_until))אחרי {{ MailV2::date($retention_until) }} האירוע יושבת והקבצים יימחקו לצמיתות.@else בסיום תקופת השמירה של החבילה האירוע יושבת והקבצים יימחקו לצמיתות.@endif</x-mail.notice>
</x-mail.layout>
