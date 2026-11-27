<?php

namespace App\Services\Helpers;

use App\Services\Mail\MailCatalog;
use App\Services\Mail\MailLedger;
use App\Services\Mail\MailPolicy;
use App\Services\Mail\MailV2;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

class MailService
{
  const MAIL_LOG_DRIVER = 'mail';
  const SYSTEM_EMAILS = 'gal.blacky@gmail.com';
  const DEFAULT_DELAY = 1; // Seconds

  private array $receivers;

  private int $delay_time = 0;

  private string $mail_track_id;

  private bool $isMock = false;

  public function __construct()
  {
    $this->mail_track_id  = Str::uuid();
  }

  /**
   * Send the mail in queue, the default is 1 second
   *
   * @param int $seconds
   * @return self
   */
  public function delay(int $seconds = self::DEFAULT_DELAY): self
  {
    $this->delay_time = $seconds;
    $this->info('Delay triggered with: ' . $seconds . ' seconds');
    return $this;
  }

  /**
   * Change the mail server non functional
   * for tests and mocks
   *
   * @return self
   */
  public function mock(): self
  {
    $this->isMock = true;
    $this->info('Mock triggered');
    return $this;
  }

  /**
   * Send the email to the receivers
   *
   * v2 (t141u): emails registered in MailCatalog get the sending policy (quiet hours / Shabbat / daily cap),
   * optional idempotency (`$options['dedupe']`) and a mail_deliveries row. NEW emails (not in the 18 legacy ones)
   * are only sent when EMAILS_V2_ENABLED. Legacy callers are unchanged.
   *
   * @param string|array $emails
   * @param string $email_class
   * @param object|array $data
   * @param array{dedupe?: string, event_id?: int} $options
   * @return bool
   */
  public function send($emails, string $email_class, $data, array $options = []): bool
  {
    try {
      $this->info('Sending mail', ['email' => $email_class, "data" => $data]);

      // If mail service is off skip
      if (!$this->isActive() || $this->isMock) {
        $this->info('Mail service is not active');
        return true;
      }

      $this->setReceivers($emails);
      if (!$this->receivers) {
        throw new Exception('No receivers found');
      }

      $meta = MailCatalog::meta($email_class);
      if ($meta && $meta['new'] && !MailV2::enabled()) {
        $this->info('New email skipped: EMAILS_V2_ENABLED is off', ['email' => $email_class]);
        return true;
      }

      $recipient = implode(',', $this->receivers);
      $category = $meta['category'] ?? MailPolicy::TRANSACTIONAL;
      $dedupe = $options['dedupe'] ?? null;
      $row = [
        'mail_key' => $meta['key'] ?? class_basename($email_class),
        'mailable' => $email_class,
        'recipient' => mb_substr($recipient, 0, 191),
        'category' => $category,
        'dedupe_key' => $dedupe,
        'event_id' => $options['event_id'] ?? null,
      ];

      if (MailLedger::seen($dedupe)) {
        $this->info('Mail skipped (already sent)', ['dedupe' => $dedupe]);
        return true;
      }

      $decision = (new MailPolicy())->decide($category, $recipient);
      if ($decision['action'] === 'skip') {
        MailLedger::record($row + ['status' => 'skipped_cap']);
        $this->info('Mail skipped by policy', ['reason' => $decision['reason']]);
        return true;
      }

      $mailable = new $email_class($data);

      if ($decision['action'] === 'defer') {
        $saved = MailLedger::record($row + ['status' => 'deferred', 'send_at' => $decision['send_at'], 'payload' => base64_encode(serialize($mailable))]);
        if ($saved) {
          $this->info('Mail deferred (quiet hours)', ['send_at' => (string) $decision['send_at']]);
          return true;
        }
        // Ledger unavailable → fail open and send now.
      }

      if ($this->delay_time) {
        Mail::to($this->receivers)->later($this->delay_time, $mailable);
        $this->info('Mail saved in queue');
      } else {
        Mail::to($this->receivers)->send($mailable);
        $this->info('Mail sent successfully');
      }
      if ($meta) {
        MailLedger::record($row + ['status' => 'sent', 'sent_at' => now()]);
      }

      return true;
    } catch (Exception $ex) {
      $this->error($ex->__toString());
      return false;
    }
  }

  /**
   * Set the receivers if single or multiple
   *
   * @param mixed $emails
   * @return void
   */
  private function setReceivers($emails)
  {
    if (is_string($emails)) {
      $this->receivers = [$emails];
    }

    if (is_array($emails)) {
      $this->receivers = $emails;
    }

    $this->info('Receivers are: ' . json_encode($this->receivers));
  }

  private function isActive(): bool
  {
    return Str::lower(trim(config('mail.status'))) === 'active';
  }

  /**
   * @param string $content
   * @param array $context
   * @return void
   */
  private function info(string $content, array $context = [])
  {
    if(isset($context["data"]) && EnvService::isProd()) {
      unset($context["data"]);
    }

    LogService::init()->info($content, array_merge($context, [LogService::TRACK_ID => $this->mail_track_id]));
  }

  /**
   * @param string $content
   * @return void
   */
  private function error(string $content)
  {
    LogService::init()->error($content, [LogService::TRACK_ID => $this->mail_track_id]);
  }
}
