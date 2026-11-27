@php use App\Services\Mail\MailV2; $p = $product ?? ''; @endphp
<x-mail.layout :subject="$mail_subject" :preheader="'התשלום התקבל. ' . ($benefit ?? '')" label="קבלה על תוספת" reason="קיבלתם את המייל הזה כי רכשתם תוספת ב־SnapShare.">
<x-slot:hero><x-mail.hero :kicker="$short_title ?? ''" title="התוספת הופעלה">{{ $benefit ?? '' }}</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p :mb="14">הפעלנו את התוספת לאירוע {{ $event_name ?? '' }}. אין צורך בשום פעולה נוספת.</x-mail.p>
<x-mail.details title="פרטי הרכישה" :rows="[['מוצר', $title ?? ''], ['כמות', (string) ($quantity ?? 1), true], ['סכום ששולם', MailV2::money($amount ?? 0), true], ['מספר רכישה', $purchase_uid ?? '', true], ['אירוע', $event_name ?? '']]" />
<x-mail.button :href="$cta_url" :label="str_starts_with($p, 'seating') ? 'לתכנון הישיבה' : 'למרכז ההודעות'" />
@if($p === 'seating_addon')
<x-mail.steps title="איך מתחילים" :items="[['הגדירו שולחנות', 'גודל, מספר ומיקום באולם.'], ['שבצו אורחים', 'ידנית או עם הצעת AI — אתם מאשרים.'], ['שלחו לאורחים את השולחן שלהם', 'קישור אישי עם מפה.']]" />
@elseif($p === 'seating_ai_topup')
<x-mail.p>עכשיו יש לכם {{ (int) ($ai_runs_total ?? 0) }} ריצות AI לסידור הישיבה.</x-mail.p>
@else
<x-mail.steps title="איך מתחילים" :items="[['בדקו את התבניות', 'הזמנה, תזכורת ו־Save the Date — מוכנות לעריכה.'], ['בחרו למי לשלוח', 'כל האורחים, רק מי שלא ענה, או קבוצה.'], ['תזמנו', 'קבעו מועד שליחה מראש.']]" />
<x-mail.notice kind="ok" title="הכול בשליטתכם">אפשר לשנות מועד, לערוך או לבטל כל הודעה מתוזמנת בכל רגע — כל עוד היא עוד לא נשלחה.</x-mail.notice>
@endif
<x-mail.notice kind="info" title="חשבונית">החשבונית נשלחת בנפרד ממערכת התשלומים.</x-mail.notice>
</x-mail.layout>
