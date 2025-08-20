@extends('mails.layouts.base')

@section('content')
    <h2>פנייתכם התקבלה</h2>
    
    <p>שלום {{ $first_name }}</p>

    <div class="info">
        <h3>מה הלאה?</h3>
        <ul>
            <li>צוות התמיכה שלנו יבחן את פנייתכם</li>
            <li>נחזור אליכם בהקדם האפשרי</li>
        </ul>
    </div>

    <div class="success">
        <p>אנו מודים לכם על הפנייה והסבלנות ומבטיחים לטפל בפנייתכם במהירות האפשרית.</p>
    </div>
@endsection
