@extends('mails.layouts.base')

@section('content')
    <h2>האירוע הושבת</h2>
    <p>שלום {{ $first_name }},</p>
    
    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <h3>פרטי האירוע:</h3>
        <p><strong>שם האירוע:</strong> {{ $event->name }}</p>
        <p><strong>תאריך השבתה:</strong> {{ now()->format('d/m/Y') }}</p>
    </div>

    <p>משמעות ההשבתה:</p>
    <ul style="list-style-type: disc; margin-right: 20px;">
        <li>לא ניתן להיכנס לעמוד האירוע</li>
        <li>כל הקבצים המשויכים לאירוע נמחקו ולא ניתנים לשחזור</li>
    </ul>

    <div class="success">
        <p>אנו מודים לכם על שבחרתם בנו לאירוע שלכם, מקווים שנהנתם.</p>
        <p>מחכים לכם באירוע הבא</p>
    </div>
@endsection
