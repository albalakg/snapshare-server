<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventDeactivateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected array $mail_data;
    protected Event $event;
    protected string $first_name;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->mail_data = $mail_data;
        $this->event = $mail_data['event'];
        $this->first_name = $mail_data['first_name'];
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'האירוע הושבת - ' . $this->event->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.eventDeactivated',
            with: [
                'event' => $this->event,
                'first_name' => $this->first_name,
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
