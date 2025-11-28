@extends('mails.layouts.base')

@section('content')
    <h2>בעיה בביצוע ההזמנה</h2>
    <p>שלום {{ $first_name }},</p>
    
    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <h3>פרטי ההזמנה</h3>
        <p><strong>מספר הזמנה:</strong> {{ $order->id }}</p>
        <p><strong>סכום:</strong> ₪{{ number_format($order->amount, 2) }}</p>
        <p><strong>תאריך:</strong> {{ $order->created_at->format('d/m/Y H:i') }}</p>
        @if($order->subscription)
            <p><strong>חבילה:</strong> {{ $order->subscription->name }}</p>
        @endif
        <p><strong>הסבר:</strong> {{ $failure_reason }}</p>
    </div>

    <p>אנא נסה/י שוב או צור/י קשר עם התמיכה אם הבעיה נמשכת.</p>
    
    <div style="text-align: center;">
        <a href="{{ $retry_url }}" class="button">ניסיון חוזר</a>
    </div>

    <div class="warning">
        <p>אנו מתנצלים על אי הנוחות.</p>
    </div>
@endsection
