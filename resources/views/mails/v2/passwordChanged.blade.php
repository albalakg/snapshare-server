<x-mail.layout :subject="$mail_subject" preheader="אם לא אתם, אפשר לאבטח את החשבון מיד." label="אבטחת החשבון" reason="קיבלתם את המייל הזה כי הסיסמה לחשבון שלכם ב־SnapShare שונתה.">
<x-slot:hero><x-mail.hero kicker="אבטחת החשבון" title="הסיסמה שלכם שונתה" glyph="✓"></x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p>הסיסמה לחשבון שלכם שונתה בהצלחה.</x-mail.p>
<x-mail.details :rows="[['מועד השינוי', $changed_at ?? '', true]]" />
<x-mail.notice kind="warn" title="לא אתם?">אפסו את הסיסמה מיד וכתבו לנו, ונעזור לאבטח את החשבון.</x-mail.notice>
<x-mail.button :href="\App\Services\Mail\MailV2::client('/forgot-password')" label="איפוס סיסמה" :secondary="true" />
</x-mail.layout>
