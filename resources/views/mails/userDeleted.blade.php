@extends('mails.layouts.base')

@section('content')
    <h2>חשבונכם נמחק בהצלחה</h2>
    <p>שלום {{ $first_name }},</p>
    
    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <h3>פרטי המחיקה:</h3>
        <p><strong>תאריך מחיקה:</strong> {{ now()->format('d/m/Y H:i') }}</p>
    </div>

    <p>כל המידע האישי שלכם, כולל:</p>
    <ul style="list-style-type: disc; margin-right: 20px;">
        <li>פרטי המשתמש</li>
        <li>קבצי האירוע</li>
    </ul>
    
    <p>נמחק לצמיתות ממערכותינו.</p>

    <div class="success">
        <p>תודה שהשתמשת בשירותינו!</p>
    </div>
    
    <p style="font-size: 12px; color: #666;">* בהתאם לחוקי הגנת הפרטיות, חלק מהמידע עשוי להישמר לתקופה מוגבלת למטרות משפטיות ורגולטוריות.</p>
@endsection
