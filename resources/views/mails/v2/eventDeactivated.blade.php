@php use App\Services\Mail\MailV2; @endphp
<x-mail.layout :subject="$mail_subject ?? ''" preheader="הקבצים נמחקו. תודה שהייתם איתנו." label="האירוע הושבת" reason="קיבלתם את המייל הזה כי תקופת השמירה של האירוע שלכם הסתיימה.">
<x-slot:hero><x-mail.hero kicker="תקופת השמירה הסתיימה" title="האירוע הושבת" glyph="">{{ $event->name }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.details :rows="[['אירוע', $event->name], ['תאריך השבתה', MailV2::date(now()), true]]" />
<x-mail.p>השבתנו את האירוע והקבצים נמחקו לצמיתות. תודה שבחרתם ב־SnapShare, נשמח ללוות גם את האירוע הבא.</x-mail.p>
<x-mail.button :href="MailV2::client('/order')" label="לאירוע חדש" :secondary="true" />
</x-mail.layout>
