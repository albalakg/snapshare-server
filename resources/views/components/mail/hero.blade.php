@props(['kicker' => '', 'title' => '', 'glyph' => '✓', 'tone' => 'default'])
@php use App\Services\Mail\MailTheme as T; $font = T::FONT; $k = $tone === 'warn' ? T::HERO_WARN : T::HERO_GOLD; @endphp
<tr><td {!! T::bgAttr('bg-hero', 'px', 'padding:26px 32px 38px 32px;') !!} align="right" dir="rtl">
@if($glyph)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin:0 0 18px 0;"><tr><td align="center" valign="middle" width="52" height="52" style="width:52px;height:52px;border:2px solid {{ T::HERO_GOLD }};border-radius:30px;font-family:Arial,sans-serif;font-size:24px;line-height:52px;color:{{ T::HERO_GOLD }};font-weight:700;">{{ $glyph }}</td></tr></table>
@endif
@if($kicker !== '')
<div style="font-family:{{ $font }};font-size:13px;line-height:18px;font-weight:700;letter-spacing:0.6px;color:{{ $k }};margin:0 0 8px 0;">{{ $kicker }}</div>
@endif
<h1 class="h1" style="margin:0;font-family:{{ $font }};font-size:30px;line-height:38px;font-weight:800;letter-spacing:-0.5px;color:{{ T::HERO_TX }};">{{ $title }}</h1>
@if(trim((string) $slot) !== '')
<p style="margin:12px 0 0 0;font-family:{{ $font }};font-size:16px;line-height:26px;color:{{ T::HERO_MUTED }};">{{ $slot }}</p>
@endif
</td></tr>
