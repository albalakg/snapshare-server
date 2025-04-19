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
    protected string $request_url;
    protected array $request_data;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->mail_data = $mail_data;
        $this->error_message = $mail_data['error_message'];
        $this->error_trace = $mail_data['error_trace'];
        $this->request_url = $mail_data['request_url'];
        $this->request_data = $mail_data['request_data'];
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
