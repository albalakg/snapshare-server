@extends('mails.layouts.base')

@section('content')
    <h2>פניית תמיכה חדשה</h2>

    <div class="info-box">
        <p><strong>מספר פנייה:</strong> {{ $ticket_id }}</p>
        <p><strong>שם:</strong> {{ $full_name }}</p>
        <p><strong>אימייל:</strong> {{ $email }}</p>
    </div>

    <div class="info-box">
        <h3>תוכן הפנייה</h3>
        <p style="white-space: pre-wrap;">{{ $text }}</p>
    </div>
@endsection
