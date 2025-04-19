@extends('mails.layouts.base')

@section('content')
    <h2>הזמנתכם אושרה!</h2>
    <p>שלום {{ $first_name }},</p>
    
    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <h3>פרטי ההזמנה:</h3>
        <p><strong>מספר הזמנה:</strong> {{ $order->id }}</p>
        <p><strong>סכום:</strong> ₪{{ number_format($order->amount, 2) }}</p>
        <p><strong>תאריך:</strong> {{ $order->created_at->format('d/m/Y H:i') }}</p>
        @if($order->subscription)
            <p><strong>חבילה:</strong> {{ $order->subscription->name }}</p>
        @endif
    </div>

    <p>מוזמנים להיכנס לממשק ניהול האירוע:</p>
    
    <div style="text-align: center;">
        <a href="{{ $event_url }}" class="button">ממשק ניהול האירוע</a>
    </div>

    <div class="success">
        <p>תודה שבחרתם בנו!</p>
    </div>
@endsection
