<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewUserSignupAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    protected string $first_name;
    protected string $last_name;
    protected string $email;
    protected string $method;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->first_name = (string) ($mail_data['first_name'] ?? '');
        $this->last_name = (string) ($mail_data['last_name'] ?? '');
        $this->email = (string) ($mail_data['email'] ?? '');
        $this->method = (string) ($mail_data['method'] ?? '');
    }

    /** Subject: v2 copy (PLAN.md) when EMAILS_V2_ENABLED, legacy subject otherwise. */
    public function mailSubject(): string
    {
        return \App\Services\Mail\MailV2::enabled()
            ? 'משתמש חדש: ' . trim($this->first_name . ' ' . $this->last_name)
            : 'משתמש חדש נרשם - ' . config('app.name');
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
            view: \App\Services\Mail\MailV2::enabled() ? 'mails.v2.newUserSignupAdmin' : 'mails.newUserSignupAdmin',
            with: [
                'mail_subject' => $this->mailSubject(),
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'email' => $this->email,
                'method' => $this->method,
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
