{{-- rows: [[label, value, ltr?], ...] — values are escaped; ltr=true wraps in dir="ltr" (numbers, money, dates). --}}
@props(['title' => '', 'rows' => []])
@php use App\Services\Mail\MailTheme as T; $font = T::FONT; $rows = array_values(array_filter($rows, fn ($r) => isset($r[1]) && $r[1] !== '' && $r[1] !== null)); $n = count($rows); @endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin:8px 0 22px 0;"><tr>
<td {!! T::bgAttr('bg-soft', '', 'border-radius:14px;padding:18px 20px;') !!} align="right">
@if($title !== '')
<div {!! T::txAttr('t-gold', "font-family:$font;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.6px;margin:0 0 6px 0;") !!}>{{ $title }}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl">
@foreach($rows as $i => $r)
@php $last = $i === $n - 1; $bd = $last ? '' : 'border-bottom:1px solid ' . T::bd('bd-soft') . ';'; $bdc = $last ? '' : ' bd-soft'; @endphp
<tr>
<td align="right" valign="top" class="t-muted{{ $bdc }}" style="font-family:{{ $font }};font-size:14px;line-height:22px;color:{{ T::tx('t-muted') }};padding:10px 0;{{ $bd }}">{{ $r[0] }}</td>
<td align="left" valign="top" class="t-ink{{ $bdc }}" style="font-family:{{ $font }};font-size:15px;line-height:22px;font-weight:700;color:{{ T::tx('t-ink') }};padding:10px 0 10px 0;{{ $bd }}">@if(!empty($r[2]))<span dir="ltr">{{ $r[1] }}</span>@else<bdi>{{ $r[1] }}</bdi>@endif</td>
</tr>
@endforeach
</table>
</td></tr></table>
