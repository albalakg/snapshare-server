<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewSupportRequestAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    protected string $full_name;
    protected string $email;
    protected string $text;
    protected string $ticket_id;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->full_name = (string) ($mail_data['full_name'] ?? '');
        $this->email = (string) ($mail_data['email'] ?? '');
        $this->text = (string) ($mail_data['text'] ?? '');
        $this->ticket_id = (string) ($mail_data['ticket_id'] ?? '');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'פניית תמיכה חדשה - ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.newSupportRequestAdmin',
            with: [
                'full_name' => $this->full_name,
                'email' => $this->email,
                'text' => $this->text,
                'ticket_id' => $this->ticket_id,
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
