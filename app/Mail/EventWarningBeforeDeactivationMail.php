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
        if (now()->lt($this->deactivation_date) && now()->diffInSeconds($this->deactivation_date) % 86400 > 0) {
            $this->days_remaining += 1;
        }
    }

    /** Subject: v2 copy (PLAN.md) when EMAILS_V2_ENABLED, legacy subject otherwise. */
    public function mailSubject(): string
    {
        return \App\Services\Mail\MailV2::enabled()
            ? 'עוד ' . ((int) ($this->days_remaining ?? 3)) . ' ימים והקבצים של ' . $this->event->name . ' יימחקו'
            : 'התראה: האירוע יושבת בקרוב - ' . $this->event->name;
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
            view: \App\Services\Mail\MailV2::enabled() ? 'mails.v2.eventWarningBeforeDeactivation' : 'mails.eventWarningBeforeDeactivation',
            with: [
                'mail_subject' => $this->mailSubject(),
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
