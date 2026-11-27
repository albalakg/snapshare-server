@props(['kind' => 'info', 'title' => ''])
@php
    use App\Services\Mail\MailTheme as T;
    $bg = ['info' => 'bg-soft', 'warn' => 'bg-warn', 'ok' => 'bg-ok'][$kind] ?? 'bg-soft';
    $bd = ['info' => 'bd-gold', 'warn' => 'bd-warn', 'ok' => 'bd-ok'][$kind] ?? 'bd-gold';
    $tt = ['info' => 't-gold', 'warn' => 't-warn', 'ok' => 't-ok'][$kind] ?? 't-gold';
    $font = T::FONT;
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin:6px 0 22px 0;"><tr>
<td class="{{ $bg }} {{ $bd }}" bgcolor="{{ T::bg($bg) }}" align="right" style="background-color:{{ T::bg($bg) }};border-right:4px solid {{ T::bd($bd) }};border-radius:12px;padding:16px 18px;">
@if($title !== '')<div class="{{ $tt }}" style="font-family:{{ $font }};font-size:15px;line-height:22px;font-weight:800;color:{{ T::tx($tt) }};margin:0 0 4px 0;">{{ $title }}</div>@endif
<div {!! T::txAttr('t-ink', "font-family:$font;font-size:14px;line-height:22px;") !!}>{{ $slot }}</div>
</td></tr></table>
