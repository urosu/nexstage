<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Alert;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the workspace owner when a critical-severity alert is created.
 *
 * Queue: default
 *
 * Guards (enforced before dispatch, in AlertObserver):
 *   - Owner must have a verified email address.
 *   - No more than one email per alert type + workspace per 24 hours.
 */
class CriticalAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Alert $alert,
        public readonly Workspace $workspace,
    ) {
        $this->onQueue('default');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Nexstage] ' . $this->alert->type . ' — ' . $this->workspace->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.critical-alert',
            text: 'emails.critical-alert-text',
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }
}
