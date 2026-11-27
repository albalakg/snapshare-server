<x-mail.layout :subject="$mail_subject" preheader="נשארו רק כמה דברים קטנים." label="הקמת האירוע" reason="קיבלתם את המייל הזה כי יש לכם אירוע ב־SnapShare שעוד לא הושלם.">
<x-slot:hero><x-mail.hero kicker="כמעט שם" :title="'עוד ' . (int) ($missing_count ?? 1) . ' צעדים והאירוע מוכן'" glyph="★">{{ $event_name ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.checklist title="מה נשאר" :items="$checklist ?? []" />
<x-mail.button :href="$event_url" label="להשלמת ההקמה" />
</x-mail.layout>
