@props(['href' => '#', 'label' => '', 'secondary' => false, 'width' => 260])
@php use App\Services\Mail\MailTheme as T; $font = T::FONT; @endphp
@if($secondary)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin:0 0 18px 0;"><tr><td align="center" class="bd-soft" style="border:1.5px solid {{ T::tx('t-ink') }};border-radius:12px;"><a href="{{ $href }}" class="t-ink" style="display:inline-block;padding:12px 24px;font-family:{{ $font }};font-size:15px;line-height:20px;font-weight:700;color:{{ T::tx('t-ink') }};text-decoration:none;border-radius:12px;">{{ $label }}</a></td></tr></table>
@else
<table role="presentation" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin:6px 0 24px 0;"><tr><td>
<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $href }}" style="height:52px;v-text-anchor:middle;width:{{ $width }}px;" arcsize="24%" stroke="f" fillcolor="{{ T::BTN_BG }}"><w:anchorlock/><center style="color:{{ T::BTN_TX }};font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">{{ $label }}</center></v:roundrect><![endif]-->
<!--[if !mso]><!-- --><a href="{{ $href }}" class="btn" style="display:inline-block;min-width:{{ $width - 56 }}px;padding:15px 28px;background-color:{{ T::BTN_BG }};border-radius:13px;font-family:{{ $font }};font-size:16px;line-height:22px;font-weight:800;color:{{ T::BTN_TX }};text-decoration:none;text-align:center;">{{ $label }}</a><!--<![endif]-->
</td></tr></table>
@endif
