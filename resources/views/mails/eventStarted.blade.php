@extends('mails.layouts.base')

@section('content')
    <h2>האירוע שלכם התחיל!</h2>
    <p>שלום {{ $first_name }},</p>
    
    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <h3>פרטי האירוע:</h3>
        <p><strong>שם האירוע:</strong> {{ $event->name }}</p>
        <p><strong>תאריך:</strong> {{ \Carbon\Carbon::parse($event->starts_at)->timezone('Asia/Jerusalem')->format('d/m/Y') }}</p>
        <p><strong>שעת התחלה:</strong> {{ \Carbon\Carbon::parse($event->starts_at)->timezone('Asia/Jerusalem')->format('H:i') }}</p>
        <p><strong>תאריך סיום:</strong> {{ \Carbon\Carbon::parse($event->finished_at)->timezone('Asia/Jerusalem')->format('d/m/Y H:i') }}</p>
    </div>

    <p>האורחים יכולים כעת להתחיל להעלות תמונות וסרטונים לאירוע.</p>
    
    <div style="text-align: center;">
        <a href="{{ $event_url }}" class="button">ממשק ניהול האירוע</a>
    </div>

    <p>אנו מאחלים לכם אירוע מוצלח ומהנה!</p>
    
    <div class="success">
        <p style="font-size: 12px; color: #666;">* תוכלו לנהל את האירוע ולצפות בתמונות המועלות בזמן אמת דרך ממשק ניהול האירוע.</p>
    </div>
@endsection
