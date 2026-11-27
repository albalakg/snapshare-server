<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailCatalog;
use App\Services\Mail\MailV2;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Dev-only email preview (t141u). 404 unless EMAILS_PREVIEW_ENABLED and never in production.
 * Renders with sample data only — nothing is sent, nothing touches the DB.
 *   /dev/emails                         index
 *   /dev/emails/{key}?variant=&legacy=1 one email (legacy=1 → old template)
 */
class EmailPreviewController extends Controller
{
    public function index(): Response
    {
        abort_unless(MailV2::previewEnabled(), 404);
        $rows = '';
        foreach (MailCatalog::keys() as $key) {
            $item = MailCatalog::get($key);
            foreach (MailCatalog::variants($key) as $v) {
                $q = $v ? '?variant=' . urlencode($v) : '';
                config(['emails.v2_enabled' => true]);
                $subject = e(MailCatalog::make($key, $v)->mailSubject());
                $rows .= '<tr><td>' . e($item['id']) . '</td><td><a href="/dev/emails/' . e($key) . $q . '">' . e($key) . ($v ? ' · ' . e($v) : '') . '</a></td><td dir="rtl">' . $subject . '</td><td>' . e($item['category']) . '</td><td>' . ($item['new'] ? 'new' : 'migrated') . '</td></tr>';
            }
        }
        $html = '<!doctype html><meta charset="utf-8"><title>SnapShare emails</title><style>body{font:14px system-ui;margin:24px}td,th{padding:6px 10px;border-bottom:1px solid #ddd;text-align:left}</style>'
            . '<h1>SnapShare emails (dev preview — nothing is sent)</h1><table><tr><th>id</th><th>key</th><th>subject</th><th>category</th><th></th></tr>' . $rows . '</table>';

        return response($html);
    }

    public function show(Request $request, string $key): Response
    {
        abort_unless(MailV2::previewEnabled() && MailCatalog::get($key), 404);
        $legacy = $request->boolean('legacy') && !MailCatalog::get($key)['new'];
        config(['emails.v2_enabled' => !$legacy]);

        return response(MailCatalog::make($key, $request->query('variant'))->render());
    }
}
