@props(['name' => ''])
@php use App\Services\Mail\MailTheme as T; @endphp
<p {!! T::txAttr('t-ink', 'margin:0 0 10px 0;font-family:' . T::FONT . ';font-size:17px;line-height:28px;') !!}>@if(trim($name) !== '')היי <strong>{{ $name }}</strong>,@else היי,@endif</p>
