<?php

namespace App\Mail;

use App\Models\Event;
use App\Services\Helpers\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssetsReadyForDownloadMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected array $mail_data;
    protected Event $event;
    protected string $first_name;
    protected int $total_assets;
    protected string $download_url;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->mail_data = $mail_data;
        $this->event = $mail_data['event'];
        $this->total_assets = $mail_data['event']['assets_count'];
        $this->first_name = $mail_data['first_name'];
        $this->download_url = $mail_data['download_url'];
    }

    /** Subject: v2 copy (PLAN.md) when EMAILS_V2_ENABLED, legacy subject otherwise. */
    public function mailSubject(): string
    {
        return \App\Services\Mail\MailV2::enabled()
            ? 'הקבצים של ' . $this->event->name . ' מוכנים להורדה'
            : 'הקבצים מוכנים להורדה - ' . $this->event->name;
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
            view: \App\Services\Mail\MailV2::enabled() ? 'mails.v2.assetsReadyForDownload' : 'mails.assetsReadyForDownload',
            with: [
                'mail_subject' => $this->mailSubject(),
                'event' => $this->event,
                'total_assets' => $this->total_assets,
                'first_name' => $this->first_name,
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
