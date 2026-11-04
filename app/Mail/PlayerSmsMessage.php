<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlayerSmsMessage extends Mailable
{
    use Queueable, SerializesModels;

    /** @param list<string> $numbers */
    public function __construct(public string $smsContent, public array $numbers) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('admin.player_sms.from_address'), config('admin.player_sms.from_name')),
            subject: config('admin.player_sms.subject'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            text: 'mail.player-sms',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(
            fn () => "MSISDN\n".implode("\n", array_map(fn (string $number): string => substr($number, 3), $this->numbers))."\n",
            'recipients.txt',
        )->withMime('text/plain')];
    }
}
