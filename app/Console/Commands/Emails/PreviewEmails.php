<?php

namespace App\Console\Commands\Emails;

use App\Services\Mail\MailCatalog;
use App\Services\Mail\MailV2;
use Illuminate\Console\Command;

/** Writes every email (sample data) to HTML files for review / screenshots. Never sends. Dev only. */
class PreviewEmails extends Command
{
    protected $signature = 'emails:preview {--out= : output dir (default storage/app/email-preview)} {--key= : only this key} {--legacy : also render the old templates}';

    protected $description = 'Render all emails to HTML with sample data (dev only, never sends)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('emails:preview is disabled in production.');
            return self::FAILURE;
        }
        $original = MailV2::enabled();
        $out = rtrim($this->option('out') ?: storage_path('app/email-preview'), '/');
        @mkdir($out, 0775, true);
        $keys = $this->option('key') ? [$this->option('key')] : MailCatalog::keys();
        $index = [];
        foreach ($keys as $key) {
            $item = MailCatalog::get($key);
            if (!$item) {
                $this->error("unknown key {$key}");
                return self::FAILURE;
            }
            foreach (MailCatalog::variants($key) as $v) {
                config(['emails.v2_enabled' => true]);
                $m = MailCatalog::make($key, $v);
                $name = strtolower($item['id']) . '-' . $key . ($v ? '-' . $v : '');
                file_put_contents("{$out}/{$name}.html", $m->render());
                $index[] = [$item['id'], $name, $m->mailSubject(), $item['category']];
                if ($this->option('legacy') && !$item['new']) {
                    config(['emails.v2_enabled' => false]);
                    file_put_contents("{$out}/legacy-{$name}.html", MailCatalog::make($key, $v)->render());
                }
            }
        }
        config(['emails.v2_enabled' => $original]);
        file_put_contents("{$out}/index.json", json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info(count($index) . " emails written to {$out}");

        return self::SUCCESS;
    }
}
