<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected array $mail_data;
    protected Order $order;
    protected string $first_name;
    protected string $event_url;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->mail_data = $mail_data;
        $this->order = $mail_data['order'];
        $this->first_name = $mail_data['first_name'];
        $this->event_url = $mail_data['event_url'];
    }

    /** Subject: v2 copy (PLAN.md) when EMAILS_V2_ENABLED, legacy subject otherwise. */
    public function mailSubject(): string
    {
        return \App\Services\Mail\MailV2::enabled()
            ? 'ההזמנה אושרה — האירוע שלכם מוכן'
            : 'אישור הזמנה #' . $this->order->id . ' - ' . config('app.name');
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
            view: \App\Services\Mail\MailV2::enabled() ? 'mails.v2.orderConfirmed' : 'mails.orderConfirmed',
            with: [
                'mail_subject' => $this->mailSubject(),
                'order' => $this->order,
                'first_name' => $this->first_name,
                'event_url' => $this->event_url,
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
