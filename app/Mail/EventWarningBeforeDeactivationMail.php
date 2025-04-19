<?php

namespace App\Mail;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventWarningBeforeDeactivationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected array $mail_data;
    protected Event $event;
    protected string $first_name;
    protected string $download_url;
    protected Carbon $deactivation_date;
    protected int $days_remaining;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->mail_data = $mail_data;
        $this->event = $mail_data['event'];
        $this->first_name = $mail_data['first_name'];
        $this->download_url = $mail_data['download_url'];
        $this->deactivation_date = $mail_data['deactivation_date'];
        $this->days_remaining = now()->diffInDays($this->deactivation_date, false);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'התראה: האירוע יושבת בקרוב - ' . $this->event->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.eventWarningBeforeDeactivation',
            with: [
                'event' => $this->event,
                'first_name' => $this->first_name,
                'deactivation_date' => $this->deactivation_date,
                'days_remaining' => $this->days_remaining,
                'download_url' => $this->download_url,
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
