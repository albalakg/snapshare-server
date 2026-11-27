@props(['subject' => '', 'preheader' => '', 'label' => '', 'reason' => ''])
@php
    use App\Services\Mail\MailTheme as T;
    $font = T::FONT;
    $home = \App\Services\Mail\MailV2::client();
    $logo = config('mail.brand.logo_url');
    $support = \App\Services\Mail\MailV2::support();
    $brand = config('mail.brand.name', 'SnapShare');
@endphp
<!DOCTYPE html>
<html lang="he" dir="rtl" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="format-detection" content="telephone=no,date=no,address=no,email=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $subject }}</title>
<!--[if gte mso 9]><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
<style>
  body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}
  table,td{mso-table-lspace:0;mso-table-rspace:0;}
  img{-ms-interpolation-mode:bicubic;border:0;outline:none;text-decoration:none;}
  body{margin:0 !important;padding:0 !important;width:100% !important;background-color:#f3eee6;}
  a{text-decoration:none;}
  @media only screen and (max-width:620px){
    .container{width:100% !important;max-width:100% !important;}
    .px{padding-left:20px !important;padding-right:20px !important;}
    .h1{font-size:26px !important;line-height:34px !important;}
    .stat{display:block !important;width:100% !important;margin-bottom:8px !important;box-sizing:border-box;}
    .stat-gap{display:none !important;}
    .btn{display:block !important;}
    .outer-pad{padding:0 !important;}
  }
  @media (prefers-color-scheme:dark){
{!! T::darkRules() !!}
  }
  {!! T::outlookRules() !!}
</style>
</head>
<body class="bg-page" style="margin:0;padding:0;background-color:#f3eee6;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f3eee6;opacity:0;">{{ $preheader }}{!! str_repeat('&nbsp;&zwnj;', 40) !!}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-page bg-outer" bgcolor="#f3eee6" style="background-color:#f3eee6;"><tr><td align="center" class="outer-pad" style="padding:24px 12px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
<table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="width:600px;max-width:600px;">
<tr><td {!! T::bgAttr('bg-hero', 'px', 'padding:22px 32px 6px 32px;border-radius:16px 16px 0 0;') !!}>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr>
    <td align="right" valign="middle" style="font-family:{{ $font }};">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr>
        <td valign="middle" style="padding-left:10px;"><a href="{{ $home }}" style="text-decoration:none;"><img src="{{ $logo }}" width="40" height="40" alt="" style="display:block;border:0;border-radius:12px;width:40px;height:40px;"></a></td>
        <td valign="middle" style="font-family:{{ $font }};font-size:22px;line-height:24px;font-weight:800;letter-spacing:-0.5px;color:{{ T::HERO_TX }};"><a href="{{ $home }}" style="color:{{ T::HERO_TX }};text-decoration:none;">{{ $brand }}</a></td>
      </tr></table>
    </td>
    <td align="left" valign="middle" style="font-family:{{ $font }};font-size:13px;line-height:18px;font-weight:600;color:{{ T::HERO_GOLD }};">{{ $label }}</td>
  </tr></table>
</td></tr>
{{ $hero ?? '' }}
<tr><td bgcolor="#c9a36a" height="4" style="height:4px;line-height:4px;font-size:0;background-color:#c9a36a;">&nbsp;</td></tr>
<tr><td {!! T::bgAttr('bg-card', 'px', 'padding:34px 32px 8px 32px;') !!} align="right" dir="rtl">
{{ $slot }}
<p {!! T::txAttr('t-ink', "margin:20px 0 26px 0;font-family:$font;font-size:15px;line-height:24px;") !!}>בשמחה,<br><strong>צוות {{ $brand }}</strong></p>
</td></tr>
<tr><td {!! T::bgAttr('bg-page', 'px', 'padding:26px 32px 8px 32px;') !!} align="center" dir="rtl">
  @if($reason !== '')
  <p {!! T::txAttr('t-foot', "margin:0 0 10px 0;font-family:$font;font-size:13px;line-height:21px;") !!}>{{ $reason }}</p>
  @endif
  <p {!! T::txAttr('t-foot', "margin:0 0 10px 0;font-family:$font;font-size:13px;line-height:21px;") !!}>צריכים עזרה? כתבו לנו ל־<a href="mailto:{{ $support }}" class="t-foot" style="color:{{ T::tx('t-foot') }};text-decoration:underline;"><bdi>{{ $support }}</bdi></a> או השיבו למייל הזה.</p>
  <p {!! T::txAttr('t-foot', "margin:0;font-family:$font;font-size:12px;line-height:19px;") !!}><bdi dir="ltr">© {{ date('Y') }} {{ $brand }}</bdi> · <a href="mailto:{{ $support }}" class="t-foot" style="color:{{ T::tx('t-foot') }};text-decoration:none;"><bdi>{{ $support }}</bdi></a></p>
</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body>
</html>
