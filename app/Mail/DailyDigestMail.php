<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the workspace owner with yesterday's (or last 7 days') hero metrics
 * and the Today's Attention list — same content as the Home dashboard hero row.
 *
 * Subject:
 *   Daily:  "Nexstage daily — 3 things to look at"
 *   Weekly: "Nexstage weekly — last 7 days"  (Mondays only, weekly_digest pref)
 *
 * Dispatched by SendDailyDigestJob, which runs from DispatchDailyDigestsJob
 * (hourly scheduler action targeting each workspace's local 5am).
 *
 * Queue: default
 *
 * @see App\Jobs\SendDailyDigestJob
 * @see PROGRESS.md Phase 3.7 — Daily digest
 */
class DailyDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array{revenue:float, orders:int, ad_spend:float, roas:float|null}  $heroMetrics
     * @param  array<int, array{text:string, href:string, severity:string}>        $attentionItems
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly ?string $narrative,
        public readonly array $heroMetrics,
        public readonly array $attentionItems,
        public readonly bool $isWeekly,
        public readonly string $startDate,
        public readonly string $endDate,
    ) {
        $this->onQueue('default');
    }

    public function envelope(): Envelope
    {
        $subject = $this->isWeekly
            ? 'Nexstage weekly — last 7 days'
            : 'Nexstage daily — 3 things to look at';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.daily-digest',
            text: 'emails.daily-digest-text',
        );
    }

    /** @return array<int, mixed> */
    public function attachments(): array
    {
        return [];
    }
}
