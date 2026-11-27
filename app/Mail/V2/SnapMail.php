<?php

namespace App\Mail\V2;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Base for the NEW v2 emails (t141u). Data is a plain array (scalars / arrays only, so it serializes
 * cleanly for the queue and for deferred sends). View: resources/views/mails/v2/{view}.blade.php.
 */
abstract class SnapMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public array $mail_data;

    public function __construct(array $mail_data)
    {
        $this->mail_data = $mail_data;
    }

    abstract public function mailSubject(): string;

    abstract protected function viewName(): string;

    protected function d(string $key, $default = '')
    {
        return data_get($this->mail_data, $key, $default);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject());
    }

    public function content(): Content
    {
        return new Content(
            view: 'mails.v2.' . $this->viewName(),
            with: $this->mail_data + ['mail_subject' => $this->mailSubject()],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
