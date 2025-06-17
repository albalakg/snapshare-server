@extends('mails.layouts.base')

@section('content')
    <h2>הקבצים שלכם מוכנים להורדה!</h2>
    
    <p>שלום {{ $first_name }},</p>
    <div class="info-box">
        <h3>פרטי האירוע:</h3>
        <p><strong>שם האירוע:</strong> {{ $event->name }}</p>
        <p><strong>מספר קבצים:</strong> {{ $total_assets }}</p>
    </div>

    <div class="success">
        <h3>הקבצים שלכם מחכים!</h3>
        <ul>
            <li>כל התמונות והסרטונים מהאירוע</li>
            <li>איכות מקורית מלאה</li>
            <li>מוכנים להורדה מיידית</li>
        </ul>
    </div>

    <div class="button-container">
        <a href="{{ $download_url }}" class="button">הורדת קבצים</a>
    </div>

    <div class="text-center">
        <p>נתקלתם בבעיה? אנחנו כאן לעזור!</p>
    </div>
@endsection
