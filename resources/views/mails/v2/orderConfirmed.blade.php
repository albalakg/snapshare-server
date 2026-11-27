@php use App\Services\Mail\MailV2; $plan = $order->subscription->name ?? null; $wa = (bool) config('whatsapp_messaging.enabled'); @endphp
<x-mail.layout :subject="$mail_subject ?? ''" :preheader="'הזמנה #' . $order->id . ' אושרה. הנה שלושת הצעדים הראשונים כדי שהאורחים יתחילו לשתף.'" label="אישור הזמנה" reason="קיבלתם את המייל הזה כי ביצעתם הזמנה ב־SnapShare.">
<x-slot:hero><x-mail.hero kicker="ההזמנה אושרה" :title="'תודה' . (($first_name ?? '') !== '' ? ', ' . $first_name : '') . ' — האירוע שלכם מוכן!'">החבילה פעילה, והאירוע שלכם מחכה. עוד כמה דקות והאורחים מתחילים לשתף.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p :mb="14">קיבלנו את התשלום והכול מוכן. הנה סיכום ההזמנה:</x-mail.p>
<x-mail.details title="פרטי ההזמנה" :rows="[
    ['מספר הזמנה', '#' . $order->id, true],
    ['חבילה', $plan],
    ['סכום ששולם', MailV2::money($order->price), true],
    ['תאריך', MailV2::date($order->created_at, 'd/m/Y · H:i'), true],
]" />
<x-mail.button :href="$event_url" label="כניסה לניהול האירוע" />
<x-mail.steps title="שלושה צעדים לפני שמתחילים" :items="[
    ['השלימו את פרטי האירוע', 'שם, תאריך ושעה — האלבום נפתח לאורחים אוטומטית.'],
    ['שתפו את קוד ה־QR', 'הדפיסו כרטיס או שלחו את הקישור לאורחים.'],
    $wa ? ['שלחו Save the Date בוואטסאפ', 'תזמנו הודעה, ושנו או בטלו אותה בכל רגע כל עוד לא נשלחה.'] : ['הזמינו את האורחים', 'שלחו להם את קישור האירוע.'],
]" />
<x-mail.notice kind="info" title="קבלה וחשבונית">החשבונית נשלחת בנפרד ממערכת התשלומים. לא קיבלתם? כתבו לנו ונשלח מחדש.</x-mail.notice>
</x-mail.layout>
