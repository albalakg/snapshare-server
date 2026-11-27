@php use App\Services\Mail\MailV2; @endphp
<x-mail.layout :subject="$mail_subject ?? ''" preheader="לא חויבתם. אפשר לנסות שוב בלחיצה אחת." label="בעיה בתשלום" reason="קיבלתם את המייל הזה כי ניסיתם לבצע הזמנה ב־SnapShare.">
<x-slot:hero><x-mail.hero kicker="התשלום לא הושלם" title="משהו השתבש בתשלום" glyph="!" tone="warn">ההזמנה שלכם עדיין שמורה, ואפשר לנסות שוב בכל רגע.</x-mail.hero></x-slot:hero>
<x-mail.greeting :name="$first_name ?? ''" />
<x-mail.p :mb="14">לא הצלחנו להשלים את התשלום על ההזמנה שלכם.</x-mail.p>
<x-mail.details title="פרטי ההזמנה" :rows="[
    ['מספר הזמנה', '#' . $order->id, true],
    ['חבילה', $order->subscription->name ?? null],
    ['סכום', MailV2::money($order->price), true],
    ['סיבה', $failure_reason ?? null],
]" />
<x-mail.button :href="$retry_url" label="ניסיון חוזר" />
<x-mail.notice kind="warn" title="ראיתם חיוב בכרטיס?">כתבו לנו עם מספר ההזמנה ונבדוק את זה מיד. אנחנו לא שומרים פרטי כרטיס אשראי.</x-mail.notice>
<x-mail.link :href="'mailto:' . MailV2::support()" label="דברו איתנו">הבעיה חוזרת?</x-mail.link>
</x-mail.layout>
