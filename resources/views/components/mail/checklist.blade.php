{{-- items: [[done(bool), text], ...] --}}
@props(['title' => '', 'items' => []])
@php use App\Services\Mail\MailTheme as T; $font = T::FONT; @endphp
@if($title !== '')<x-mail.h2>{{ $title }}</x-mail.h2>@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin:0 0 10px 0;">
@foreach($items as $it)
@php $cls = $it[0] ? 't-ok' : 't-warn'; @endphp
<tr><td width="30" valign="top" align="right" class="{{ $cls }}" style="padding:0 0 12px 0;font-family:Arial,sans-serif;font-size:18px;line-height:24px;font-weight:700;color:{{ T::tx($cls) }};">{{ $it[0] ? '✓' : '○' }}</td>
<td valign="top" align="right" {!! T::txAttr('t-ink', "padding:0 0 12px 0;font-family:$font;font-size:16px;line-height:24px;") !!}>{{ $it[1] }}</td></tr>
@endforeach
</table>
