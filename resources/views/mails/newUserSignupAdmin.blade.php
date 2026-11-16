@extends('mails.layouts.base')

@section('content')
    <h2>משתמש חדש נרשם</h2>

    <div class="info-box">
        <p><strong>שם:</strong> {{ $first_name }} {{ $last_name }}</p>
        <p><strong>אימייל:</strong> {{ $email }}</p>
        <p><strong>אופן הרשמה:</strong> {{ $method }}</p>
    </div>
@endsection
