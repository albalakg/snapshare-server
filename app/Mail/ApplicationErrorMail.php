<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationErrorMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected string $error_message;

    protected string $error_trace;

    protected string $request_url;

    protected array $request_data;

    protected string $error_file;

    protected int $error_line;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mail_data)
    {
        $this->error_message = (string) ($mail_data['error_message'] ?? '');
        $this->error_trace = (string) ($mail_data['error_trace'] ?? '');
        $this->request_url = (string) ($mail_data['request_url'] ?? '');
        $requestData = $mail_data['request_data'] ?? [];
        $this->request_data = is_array($requestData)
            ? $requestData
            : ['payload' => is_scalar($requestData) ? (string) $requestData : get_debug_type($requestData)];

        $this->error_file = (string) ($mail_data['error_file'] ?? '');
        $this->error_line = (int) ($mail_data['error_line'] ?? 0);
        if ($this->error_file === '' && $this->error_trace !== '') {
            if (preg_match('/^\#\d+\s+(.+)\((\d+)\):/m', $this->error_trace, $m)) {
                $this->error_file = $m[1];
                $this->error_line = (int) $m[2];
            }
        }
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'שגיאת מערכת - ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.applicationError',
            with: [
                'data' => $this->exceptionViewData(),
                'error_message' => $this->error_message,
                'error_trace' => $this->error_trace,
                'request_url' => $this->request_url,
                'request_data' => $this->request_data,
            ]
        );
    }

    /**
     * Throwable-shaped object for the Blade template (built from scalar mail payload for queue safety).
     */
    private function exceptionViewData(): object
    {
        $message = $this->error_message;
        $file = $this->error_file;
        $line = $this->error_line;
        $trace = $this->error_trace;

        return new class ($message, $file, $line, $trace) {
            public function __construct(
                private string $message,
                private string $file,
                private int $line,
                private string $trace,
            ) {
            }

            public function getMessage(): string
            {
                return $this->message;
            }

            public function getFile(): string
            {
                return $this->file;
            }

            public function getLine(): int
            {
                return $this->line;
            }

            public function __toString(): string
            {
                return $this->trace;
            }
        };
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
