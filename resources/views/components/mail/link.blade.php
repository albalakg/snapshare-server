@props(['href' => '#', 'label' => ''])
@php use App\Services\Mail\MailTheme as T; @endphp
<p {!! T::txAttr('t-muted', 'margin:0 0 14px 0;font-family:' . T::FONT . ';font-size:14px;line-height:22px;') !!}>{{ $slot }} <a href="{{ $href }}" class="t-link" style="color:{{ T::tx('t-link') }};font-weight:700;text-decoration:underline;">{{ $label }}</a></p>
