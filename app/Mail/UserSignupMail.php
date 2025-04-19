<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserSignupMail extends Mailable
{
    use Queueable, SerializesModels;

    protected array $mail_data;
    protected string $first_name;
    protected string $order_url;
    protected string $verification_url;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->mail_data = $mail_data;
        $this->first_name = $mail_data['first_name'];
        $this->verification_url = $mail_data['verification_url'];
        $this->order_url = $mail_data['order_url'];
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ברוכים הבאים ל' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.userSignup',
            with: [
                'first_name' => $this->first_name,
                'verification_url' => $this->verification_url,
                'order_url' => $this->order_url,
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
