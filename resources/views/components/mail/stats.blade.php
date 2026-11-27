{{-- items: [[number, label, tone(t-ok|t-warn|t-gold|t-ink)], ...] --}}
@props(['items' => []])
@php use App\Services\Mail\MailTheme as T; $font = T::FONT; $items = array_values($items); $n = max(1, count($items)); $w = intdiv(100, $n); @endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin:8px 0 22px 0;table-layout:fixed;"><tr>
@foreach($items as $i => $it)
@if($i > 0)<td class="stat-gap" width="8" style="width:8px;font-size:0;line-height:0;">&nbsp;</td>@endif
<td class="stat bg-soft" width="{{ $w }}%" valign="top" align="center" bgcolor="{{ T::bg('bg-soft') }}" style="width:{{ $w }}%;background-color:{{ T::bg('bg-soft') }};border-radius:14px;padding:16px 8px;">
<div class="{{ $it[2] ?? 't-ink' }}" style="font-family:{{ $font }};font-size:32px;line-height:36px;font-weight:800;color:{{ T::tx($it[2] ?? 't-ink') }};"><bdi>{{ $it[0] }}</bdi></div>
<div {!! T::txAttr('t-muted', "font-family:$font;font-size:13px;line-height:18px;margin-top:2px;") !!}>{{ $it[1] }}</div></td>
@endforeach
</tr></table>
