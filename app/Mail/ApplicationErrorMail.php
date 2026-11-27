<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationErrorMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected string $error_message;

    protected string $error_trace;

    protected string $request_url;

    protected array $request_data;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->error_message = (string) ($mail_data['error_message'] ?? '');
        $this->error_trace = (string) ($mail_data['error_trace'] ?? '');
        $this->request_url = (string) ($mail_data['request_url'] ?? '');
        $requestData = $mail_data['request_data'] ?? [];
        $this->request_data = is_array($requestData)
            ? $requestData
            : ['payload' => is_scalar($requestData) ? (string) $requestData : get_debug_type($requestData)];
    }

    /** Subject: v2 copy (PLAN.md) when EMAILS_V2_ENABLED, legacy subject otherwise. */
    public function mailSubject(): string
    {
        return \App\Services\Mail\MailV2::enabled()
            ? '[' . app()->environment() . '] שגיאה: ' . mb_substr($this->error_message, 0, 80)
            : 'שגיאת מערכת - ' . config('app.name');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject(),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: \App\Services\Mail\MailV2::enabled() ? 'mails.v2.applicationError' : 'mails.applicationError',
            with: [
                'mail_subject' => $this->mailSubject(),
                'error_message' => $this->error_message,
                'error_trace' => $this->error_trace,
                'request_url' => $this->request_url,
                'request_data' => $this->request_data,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
