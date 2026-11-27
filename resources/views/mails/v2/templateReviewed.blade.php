@php $ok = ($status ?? '') === 'approved'; @endphp
<x-mail.layout :subject="$mail_subject" :preheader="$ok ? 'אפשר לשלוח אותה מעכשיו.' : 'ערכו את הנוסח ושלחו שוב לבדיקה.'" label="תבניות הודעה" reason="קיבלתם את המייל הזה כי שלחתם תבנית הודעה לבדיקה של Meta.">
<x-slot:hero><x-mail.hero :kicker="$ok ? 'אושרה' : 'נדחתה'" :title="$ok ? 'התבנית אושרה' : 'התבנית נדחתה'" :glyph="$ok ? '✓' : '!'" :tone="$ok ? 'default' : 'warn'">{{ $template_title ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.details :rows="[['תבנית', $template_title ?? ''], ['אירוע', $event_name ?? ''], ['סטטוס', $ok ? 'אושרה' : 'נדחתה']]" />
@if($ok)
<x-mail.p>אפשר לשלוח אותה מעכשיו, או לתזמן לשליחה מאוחרת יותר.</x-mail.p>
@else
<x-mail.notice kind="warn" title="למה נדחתה?">{{ $reason ?: 'Meta לא פירטה סיבה.' }} ערכו את הנוסח ושלחו שוב — לא נספרת הודעה.</x-mail.notice>
@endif
<x-mail.button :href="$templates_url" label="לתבניות" />
</x-mail.layout>
