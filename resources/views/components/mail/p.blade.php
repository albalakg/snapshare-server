@props(['muted' => false, 'size' => 16, 'mb' => 16])
@php use App\Services\Mail\MailTheme as T; $lh = (int) round($size * 1.65); @endphp
<p {!! T::txAttr($muted ? 't-muted' : 't-ink', "margin:0 0 {$mb}px 0;font-family:" . T::FONT . ";font-size:{$size}px;line-height:{$lh}px;") !!}>{{ $slot }}</p>
