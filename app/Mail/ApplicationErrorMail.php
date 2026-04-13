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

    protected array $mail_data;

    protected string $error_message;

    protected string $error_trace;

    protected string $error_file;

    protected int $error_line;

    protected string $request_url;

    protected array $request_data;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->mail_data = $mail_data;
        $this->error_message = (string) ($mail_data['error_message'] ?? '');
        $this->error_trace = (string) ($mail_data['error_trace'] ?? '');
        $this->error_file = (string) ($mail_data['error_file'] ?? '');
        $this->error_line = (int) ($mail_data['error_line'] ?? 0);
        $this->request_url = (string) ($mail_data['request_url'] ?? '');
        $requestData = $mail_data['request_data'] ?? [];
        $this->request_data = is_array($requestData)
            ? $requestData
            : ['payload' => is_scalar($requestData) ? (string) $requestData : get_debug_type($requestData)];
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'שגיאת מערכת - ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.applicationError',
            with: [
                'error_message' => $this->error_message,
                'error_trace' => $this->error_trace,
                'error_file' => $this->error_file,
                'error_line' => $this->error_line,
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
