@extends('mails.layouts.base')

@section('content')
    <h2>התראה: האירוע שלכם עומד להיות מושבת</h2>
    <p>שלום {{ $first_name }},</p>
    
    <div class="info-box">
        <h3>פרטי האירוע</h3>
        <p><strong>שם האירוע:</strong> {{ $event->name }}</p>
        <p><strong>ימים להשבתה:</strong> {{ $days_remaining }}</p>
        <p><strong>תאריך השבתה:</strong> {{ $deactivation_date->format('d/m/Y') }}</p>
    </div>

    <div class="note">
        <p>לאחר השבתת האירוע:</p>
        <ul>
            <li>לא ניתן להיכנס לעמוד האירוע</li>
            <li>כל הקבצים המשויכים לאירוע נמחקו ולא ניתנים לשחזור</li>
        </ul>
    </div>

    <div class="button-container">
        <p>אנו ממליצים להיכנס ולהוריד את הקבצים ברגע זה.</p>
        <a href="{{ $download_url }}" class="button secondary">הורדת קבצים</a>
    </div>

@endsection
