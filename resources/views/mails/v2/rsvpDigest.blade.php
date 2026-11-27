@php $c = $counts ?? []; @endphp
<x-mail.layout :subject="$mail_subject" :preheader="(int) ($answered ?? 0) . ' מתוך ' . (int) ($total ?? 0) . ' אורחים כבר ענו.'" label="עדכון אישורי הגעה" reason="קיבלתם את הדוח היומי כי אישורי ההגעה פתוחים באירוע שלכם.">
<x-slot:hero><x-mail.hero kicker="ב־24 השעות האחרונות" :title="(int) ($new_count ?? 0) . ' תשובות חדשות'" glyph="">{{ (int) ($answered ?? 0) }} מתוך {{ (int) ($total ?? 0) }} אורחים כבר ענו להזמנה ל{{ $event_name ?? '' }}.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.stats :items="[[number_format((int) ($c['yes'] ?? 0)), 'מגיעים', 't-ok'], [number_format((int) ($c['no'] ?? 0)), 'לא מגיעים', 't-warn'], [number_format((int) ($c['maybe'] ?? 0)), 'אולי', 't-gold']]" />
@if((int) ($c['pending'] ?? 0) > 0)
<x-mail.p :muted="true" :size="15" :mb="4">עוד {{ (int) $c['pending'] }} אורחים עדיין לא ענו. אפשר לשלוח להם תזכורת בלחיצה.</x-mail.p>
@endif
@if(!empty($recent))
<x-mail.people title="תשובות אחרונות" :rows="$recent" />
@endif
@if((int) ($review_count ?? 0) > 0)
<x-mail.notice kind="info" :title="(int) $review_count . ' תגובות חופשיות ממתינות לבדיקה'">ה־AI מציע סיווג לתשובות חופשיות — אתם מחליטים אם ליישם. שום דבר לא משתנה אוטומטית.</x-mail.notice>
@endif
<x-mail.button :href="$guests_url" label="לצפייה באורחים" />
</x-mail.layout>
