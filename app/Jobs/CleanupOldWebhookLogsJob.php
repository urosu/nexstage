<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes webhook_logs rows older than 30 days.
 *
 * Queue:   low
 * Timeout: 120 s
 * Tries:   2
 * Backoff: [60, 300] s
 *
 * Retention: 30 days (GDPR — payload contains customer PII, per spec §Data Retention).
 * Scheduled weekly on Sunday at 03:15 UTC (routes/console.php).
 */
class CleanupOldWebhookLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $cutoff  = now()->subDays(30)->toDateTimeString();
        $deleted = DB::table('webhook_logs')
            ->where('created_at', '<', $cutoff)
            ->delete();

        Log::info('CleanupOldWebhookLogsJob: completed', ['deleted' => $deleted]);
    }
}
