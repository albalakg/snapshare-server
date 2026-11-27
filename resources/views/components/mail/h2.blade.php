@php use App\Services\Mail\MailTheme as T; @endphp
<h2 {!! T::txAttr('t-ink', 'margin:28px 0 12px 0;font-family:' . T::FONT . ';font-size:19px;line-height:26px;font-weight:700;') !!}>{{ $slot }}</h2>
