<?php

namespace App\Services\Helpers;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

class MailService
{
  const MAIL_LOG_DRIVER = 'mail';
  const SYSTEM_EMAILS = 'gal.blacky@gmail.com';
  const DEFAULT_DELAY = 1; // Seconds

  /**
   * @var array
   */
  private array $receivers;

  /**
   * @var int
   */
  private int $delay_time = 0;

  /**
   * @var string
   */
  private string $mail_track_id;

  /**
   * @var Boolean
  */
  private bool $isMock = false;

  public function __construct()
  {
    $this->mail_track_id  = Str::uuid();
    $this->info('Mail service triggered');
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
   * @param string|array $emails
   * @param string $email_class
   * @param object|array $data
   * @return bool
   */
  public function send($emails, string $email_class, $data): bool
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

      if ($this->delay_time) {
        Mail::to($this->receivers)->later($this->delay_time, new $email_class($data));
        $this->info('Mail saved in queue');
      } else {
        Mail::to($this->receivers)->send(new $email_class($data));
        $this->info('Mail sent successfully');
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
    return config('mail.status') === 'active';
  }

  /**
   * @param string $content
   * @return void
   */
  private function info(string $content)
  {
    LogService::init()->info($content, [LogService::TRACK_ID => $this->mail_track_id]);
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
