@extends('mails.layouts.base')

@section('content')
    <h2>ברוכים הבאים!</h2>
    
    <p>שלום {{ $first_name }},</p>

    <div class="info-box">
        <h3>השלמת הרשמה</h3>
        <p>כדי להשלים את תהליך ההרשמה ולהתחיל להשתמש בשירות, אנא אמתו את כתובת האימייל שלך.</p>
    </div>
    
    <div class="button-container">
        <a href="{{ $verification_url }}" class="button">אימות כתובת אימייל</a>
    </div>

    <p>לאחר אימות המייל, תוכלו להמשיך לרכישת החבילה שלכם:</p>
    <a href="{{ $order_url }}" class="button">הזמנת החבילה</a>

    <div class="success">
        <span>תודה רבה שהצטרפתם אלינו ושבחרתם בנו לאירוע שלכם!</span>
    </div>

@endsection
