@extends('mails.layouts.base')

@section('content')
    <h2>החבילה שלכם שודרגה!</h2>
    
    <p>שלום {{ $first_name }},</p>

    <div class="success">
        <h3>פרטי החבילה החדשה:</h3>
        <p><strong>שם החבילה:</strong> {{ $subscription->name }}</p>
        <p><strong>תאריך שדרוג:</strong> {{ now()->format('d/m/Y') }}</p>
    </div>

    <div class="success">
        <h3>במה השתדרגנו?</h3>
        <ul>
            <li>העלאת קבצים ללא הגבלה</li>
            <li>האירוע נשמר ל30 יום לאחר סיום האירוע</li>
        </ul>
    </div>

    <div class="text-center">
        <p>תודה שבחרתם לשדרג!</p>
    </div>
@endsection
