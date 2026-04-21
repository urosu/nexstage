<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Holiday;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the workspace owner N days before a holiday to remind them to prepare ad campaigns.
 *
 * N = workspace_settings.holiday_notification_days (0 = disabled).
 * Dispatched by SendHolidayNotificationsJob, which runs daily.
 *
 * Queue: default
 */
class HolidayReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Holiday $holiday,
        public readonly Workspace $workspace,
        public readonly int $daysAway,
    ) {
        $this->onQueue('default');
    }

    public function envelope(): Envelope
    {
        $label  = $this->daysAway === 1 ? 'tomorrow' : "in {$this->daysAway} days";
        $prefix = $this->holiday->type === 'commercial' ? 'Upcoming sale event' : 'Upcoming holiday';

        return new Envelope(
            subject: "[Nexstage] {$prefix}: {$this->holiday->name} {$label} — {$this->workspace->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.holiday-reminder',
            text: 'emails.holiday-reminder-text',
        );
    }

    /** @return array<int, mixed> */
    public function attachments(): array
    {
        return [];
    }
}
