@extends('mails.layouts.base')

@section('content')
    <h2>האירוע שלכם הסתיים!</h2>
    <p>שלום {{ $first_name }},</p>
    
    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <h3>סיכום האירוע:</h3>
        <p><strong>שם האירוע:</strong> {{ $event->name }}</p>
        <p><strong>תאריך:</strong> {{ $event->date->format('d/m/Y') }}</p>
        <p><strong>מספר קבצים שהועלו:</strong> {{ $event->assets_count }}</p>
    </div>

    <p>
        אנו ממליצים להיכנס ולהוריד את הקבצים כמה שיותר מוקדם.
        <br>
        שימו לב, ברגע שהאירוע ייסגר כל הקבצים יימחקו.
    </p>
    
    <div style="text-align: center;">
        <a href="{{ $event_url }}" class="button">צפייה בגלריית האירוע</a>
    </div>

    <div class="success">
        <p>תודה שבחרתם בשירות שלנו לאירוע שלכם!</p>
    </div>
@endsection
