<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventFinishedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected array $mail_data;
    protected Event $event;
    protected string $first_name;
    protected string $event_url;
    protected $retention_until = null;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->mail_data = $mail_data;
        $this->event = $mail_data['event'];
        $this->first_name = $mail_data['first_name'];
        $this->event_url = $mail_data['event_url'];
        $this->retention_until = $mail_data['retention_until'] ?? null;
        
        // Load the assets count
        if ($this->event->exists) {
            $this->event->loadCount('assets');
        }
    }

    /** Subject: v2 copy (PLAN.md) when EMAILS_V2_ENABLED, legacy subject otherwise. */
    public function mailSubject(): string
    {
        return \App\Services\Mail\MailV2::enabled()
            ? 'תודה על אירוע מדהים! הנה הסיכום — ' . $this->event->name
            : 'האירוע הסתיים - ' . $this->event->name;
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
            view: \App\Services\Mail\MailV2::enabled() ? 'mails.v2.eventFinished' : 'mails.eventFinished',
            with: [
                'mail_subject' => $this->mailSubject(),
                'event' => $this->event,
                'first_name' => $this->first_name,
                'event_url' => $this->event_url,
                'retention_until' => $this->retention_until,
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
