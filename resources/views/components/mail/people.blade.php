{{-- rows: [[name, meta, chip, tone(t-ok|t-warn|t-gold)], ...] — initials only, never photos. --}}
@props(['title' => '', 'rows' => []])
@php use App\Services\Mail\MailTheme as T; use App\Services\Mail\MailV2; $font = T::FONT; $bd = 'border-bottom:1px solid ' . T::bd('bd-soft') . ';'; @endphp
@if($title !== '')<x-mail.h2>{{ $title }}</x-mail.h2>@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin:0 0 20px 0;">
@foreach($rows as $r)
@php $tone = $r[3] ?? 't-gold'; $chipBg = $tone === 't-ok' ? 'bg-ok' : ($tone === 't-warn' ? 'bg-warn' : 'bg-chip'); @endphp
<tr>
<td width="48" valign="middle" align="right" class="bd-soft" style="padding:10px 0;{{ $bd }}"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" valign="middle" width="38" height="38" class="bg-chip t-ink" style="width:38px;height:38px;border-radius:19px;background-color:{{ T::bg('bg-chip') }};font-family:{{ $font }};font-size:14px;line-height:38px;font-weight:800;color:{{ T::tx('t-ink') }};">{{ MailV2::initials((string) $r[0]) }}</td></tr></table></td>
<td valign="middle" align="right" class="bd-soft" style="padding:10px 0;{{ $bd }}font-family:{{ $font }};"><div {!! T::txAttr('t-ink', 'font-size:15px;line-height:22px;font-weight:700;') !!}>{{ $r[0] }}</div>@if(!empty($r[1]))<div {!! T::txAttr('t-muted', 'font-size:13px;line-height:18px;') !!}>{{ $r[1] }}</div>@endif</td>
<td valign="middle" align="left" class="bd-soft" style="padding:10px 0;{{ $bd }}font-family:{{ $font }};">@if(!empty($r[2]))<span class="{{ $tone }} {{ $chipBg }}" style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;line-height:18px;font-weight:700;color:{{ T::tx($tone) }};background-color:{{ T::bg($chipBg) }};">{{ $r[2] }}</span>@endif</td>
</tr>
@endforeach
</table>
