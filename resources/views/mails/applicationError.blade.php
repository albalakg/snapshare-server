@extends('mails.layouts.base')

@section('content')
    <h2>שגיאת מערכת</h2>

    <div class="warning">
        <p><strong>הודעה:</strong> {{ $error_message }}</p>
    </div>

    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <h3>מיקום בקוד</h3>
        @if($request_url !== '')
            <p><strong>כתובת בקשה:</strong> {{ $request_url }}</p>
        @endif
    </div>

    <div class="info-box">
        <h3>מעקב (stack trace)</h3>
        <pre style="white-space: pre-wrap; word-break: break-word; font-size: 11px; margin: 0;">{{ $error_trace }}</pre>
    </div>

    @if(count($request_data) > 0)
        <div class="info-box">
            <h3>נתוני בקשה (מסוננים)</h3>
            <pre style="white-space: pre-wrap; word-break: break-word; font-size: 11px; margin: 0;">{{ print_r($request_data, true) }}</pre>
        </div>
    @endif
@endsection
