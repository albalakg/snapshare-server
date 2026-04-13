@extends('mails.layouts.base')

@section('content')
    <h2>שגיאת מערכת</h2>

    <div class="warning">
        <p><strong>הודעה:</strong> {{ $data['error_message'] ?? "" }}</p>
    </div>

    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <h3>מיקום בקוד</h3>
        <p><strong>קובץ:</strong> {{ $data['error_file'] ?? "" ?: '—' }}</p>
        <p><strong>שורה:</strong> {{ $data['error_line'] ?? "" > 0 ? $data['error_line'] ?? "" : '—' }}</p>
        @if($data['request_url'] ?? "" !== '')
            <p><strong>כתובת בקשה:</strong> {{ $data['request_url'] ?? "" }}</p>
        @endif
    </div>

    <div class="info-box">
        <h3>מעקב (stack trace)</h3>
        <pre style="white-space: pre-wrap; word-break: break-word; font-size: 11px; margin: 0;">{{ $data['error_trace'] ?? "" }}</pre>
    </div>

    @if(count($data['request_data'] ?? "") > 0)
        <div class="info-box">
            <h3>נתוני בקשה (מסוננים)</h3>
            <pre style="white-space: pre-wrap; word-break: break-word; font-size: 11px; margin: 0;">{{ print_r($data['request_data'] ?? "", true) }}</pre>
        </div>
    @endif
@endsection
