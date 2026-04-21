<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MonthlyReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the monthly PDF report for a single workspace and stores it under
 * storage/app/reports/monthly/{workspace_id}/{YYYY-MM}.pdf.
 *
 * Triggered by:
 *   - routes/console.php monthlyOn(1, '08:00') — auto-run for every active
 *     workspace with a store and/or ads, using the previous calendar month.
 *
 * Reads from: MonthlyReportService (daily_snapshots, ad_insights, orders, order_items).
 * Writes to:  local disk under reports/monthly/{workspace_id}/.
 *
 * Queue: low. Keeping this off critical queues — the PDF is a nice-to-have
 * delivered by the 1st-of-month digest, not time-critical like webhooks.
 *
 * @see PLANNING.md section 12.5 (Monthly PDF reports)
 */
class GenerateMonthlyReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $monthStart,
    ) {
        $this->onQueue('low');
    }

    public function handle(MonthlyReportService $service): void
    {
        $month = CarbonImmutable::parse($this->monthStart)->startOfMonth();
        $data = $service->build($this->workspaceId, $month);

        $pdf = Pdf::loadView('reports.monthly', $data)->setPaper('a4');

        $path = sprintf(
            'reports/monthly/%d/%s.pdf',
            $this->workspaceId,
            $month->format('Y-m'),
        );

        Storage::disk('local')->put($path, $pdf->output());

        Log::info('GenerateMonthlyReportJob: report written', [
            'workspace_id' => $this->workspaceId,
            'month' => $month->format('Y-m'),
            'path' => $path,
        ]);
    }
}
