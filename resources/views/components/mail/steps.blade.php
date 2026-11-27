{{-- items: [[title, description], ...] --}}
@props(['title' => '', 'items' => []])
@php use App\Services\Mail\MailTheme as T; $font = T::FONT; @endphp
@if($title !== '')<x-mail.h2>{{ $title }}</x-mail.h2>@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin:0 0 8px 0;">
@foreach(array_values($items) as $i => $it)
<tr>
<td width="40" valign="top" align="right" style="padding:0 0 16px 0;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" valign="middle" width="30" height="30" class="bg-chip t-ink" style="width:30px;height:30px;border-radius:15px;background-color:{{ T::bg('bg-chip') }};font-family:{{ $font }};font-size:14px;line-height:30px;font-weight:800;color:{{ T::tx('t-ink') }};">{{ $i + 1 }}</td></tr></table></td>
<td valign="top" align="right" style="padding:0 0 16px 0;font-family:{{ $font }};"><div {!! T::txAttr('t-ink', 'font-size:16px;line-height:24px;font-weight:700;') !!}>{{ $it[0] }}</div>@if(!empty($it[1]))<div {!! T::txAttr('t-muted', 'font-size:14px;line-height:22px;') !!}>{{ $it[1] }}</div>@endif</td>
</tr>
@endforeach
</table>
