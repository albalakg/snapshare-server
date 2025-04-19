<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionUpgradedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected array $mail_data;
    protected string $first_name;
    protected Subscription $subscription;
    protected string $manage_url;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->mail_data = $mail_data;
        $this->first_name = $mail_data['first_name'];
        $this->subscription = $mail_data['subscription'];
        $this->manage_url = $mail_data['manage_url'];
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'החבילה שלך שודרגה! - ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.subscriptionUpgraded',
            with: [
                'first_name' => $this->first_name,
                'subscription' => $this->subscription,
                'manage_url' => $this->manage_url,
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
