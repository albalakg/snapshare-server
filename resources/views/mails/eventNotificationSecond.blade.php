@extends('mails.layouts.base')

@section('content')
    <h2>זה כמעט כאן!</h2>

    <p>שלום {{ $first_name }},</p>

    <div class="info-box">
        <h3>עוד 8 שעות וזה מתחיל ✨</h3>
        <p>
            בעוד פחות מ־8 שעות האירוע שלכם ב־<strong>{{ $event->name }}</strong> יוצא לדרך!  
            זה הרגע האחרון לעבור על כל הפרטים ולהבטיח שהכל עובד כמו שצריך.
        </p>
    </div>

    <p>מומלץ להיכנס לעמוד האירוע ולוודא שהכל מוכן:</p>

    <div class="button-container">
        <a href="{{ $event_url }}" class="button">פתיחת עמוד האירוע</a>
    </div>

    <p>
        כל התמונות, הסרטונים וההעלאות מהאורחים שלכם כבר יופיעו שם בזמן אמת.  
        אנחנו כאן כדי לוודא שיהיה לכם אירוע חלק ובלתי נשכח.
    </p>

    <div class="success">
        <span>מאחלים לכם אירוע מושלם – ותודה שבחרתם בנו! 🎊</span>
    </div>
@endsection
