<?php

namespace App\Services\Mail;

/** Small facade for the v2 email flags / links. */
class MailV2
{
    public static function enabled(): bool
    {
        return (bool) config('emails.v2_enabled', false);
    }

    public static function previewEnabled(): bool
    {
        return (bool) config('emails.preview_enabled', false) && !app()->environment('production');
    }

    public static function client(string $path = ''): string
    {
        return rtrim((string) config('app.client_url'), '/') . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    public static function support(): string
    {
        return (string) config('mail.brand.support_email', 'hello@snapshare-live.com');
    }

    public static function date($value, string $format = 'd/m/Y'): string
    {
        if (!$value) {
            return '';
        }

        return \Carbon\Carbon::parse($value)->timezone('Asia/Jerusalem')->format($format);
    }

    public static function money($amount): string
    {
        return '₪' . number_format((float) $amount, 2);
    }

    /** Price of an add-on product from config/addons.php (never hard-coded in copy). */
    public static function addonPrice(string $product): string
    {
        return self::money(config("addons.products.$product.price", 0));
    }

    /** Hebrew initials for the people block (no photos). */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $out = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $out .= mb_substr($p, 0, 1);
        }

        return $out !== '' ? $out : '•';
    }
}
