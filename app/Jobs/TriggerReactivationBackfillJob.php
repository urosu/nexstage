<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AdAccount;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Triggers catch-up imports for all integrations after a workspace reactivates
 * following a trial expiry freeze.
 *
 * Triggered by: SyncBillingPlanFromStripe listener when workspace transitions
 *               from frozen (billing_plan=null, trial expired) to active.
 *
 * Dispatches:
 *   - WooCommerceHistoricalImportJob for each active store
 *   - AdHistoricalImportJob for each ad account
 *   - GscHistoricalImportJob for each GSC property
 *
 * Each import covers the gap period: from $gapStart (the day the workspace was
 * frozen — i.e. trial_ends_at) to yesterday. Existing completed imports are
 * restarted from the gap date only; the checkpoint is cleared so the job starts
 * fresh from yesterday → gapStart (newest-first).
 *
 * Queue: low (bulk import work, no urgency)
 *
 * See: PLANNING.md "Reactivation after freeze"
 */
class TriggerReactivationBackfillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int    $workspaceId,
        private readonly string $gapStart, // Y-m-d — the day the freeze began (trial_ends_at)
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $workspace = Workspace::withoutGlobalScopes()
            ->select(['id', 'billing_plan', 'trial_ends_at'])
            ->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        // Safety check: only run when the workspace is actually active now.
        // This prevents a race condition where the job fires but the subscription
        // webhook hasn't fully committed yet (extremely unlikely but defensive).
        if ($workspace->billing_plan === null) {
            Log::warning('TriggerReactivationBackfillJob: workspace still has no billing plan, skipping', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        $gapStart = Carbon::parse($this->gapStart)->startOfDay();
        $dispatched = 0;

        // ── WooCommerce stores ────────────────────────────────────────────────
        $stores = Store::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->select(['id', 'workspace_id', 'historical_import_status'])
            ->get();

        foreach ($stores as $store) {
            // Skip stores that were never imported (no historical import started).
            // They'll follow the normal initial import flow when the user re-enters onboarding.
            if ($store->historical_import_status === null) {
                continue;
            }

            // Reset state for gap-period re-import.
            // Why clear checkpoint: the checkpoint from the old import points to a date
            // before the gap — leaving it would cause the job to resume from the wrong
            // position and skip the gap. Starting fresh (checkpoint=null) means the job
            // iterates from yesterday back to $gapStart, which is exactly the gap.
            $store->update([
                'historical_import_status'           => 'pending',
                'historical_import_from'             => $gapStart->toDateString(),
                'historical_import_checkpoint'       => null,
                'historical_import_progress'         => null,
                'historical_import_started_at'       => null,
                'historical_import_completed_at'     => null,
                'historical_import_duration_seconds' => null,
                'historical_import_total_orders'     => null,
            ]);

            $syncLog = SyncLog::create([
                'workspace_id'  => $this->workspaceId,
                'syncable_type' => Store::class,
                'syncable_id'   => $store->id,
                'job_type'      => WooCommerceHistoricalImportJob::class,
                'status'        => 'queued',
                'queue'         => 'imports',
                'scheduled_at'  => now(),
            ]);

            WooCommerceHistoricalImportJob::dispatch($store->id, $this->workspaceId, $syncLog->id);
            $dispatched++;
        }

        // ── Ad accounts ──────────────────────────────────────────────────────
        $adAccounts = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->select(['id', 'workspace_id', 'historical_import_status'])
            ->get();

        foreach ($adAccounts as $account) {
            if ($account->historical_import_status === null) {
                continue;
            }

            $account->update([
                'historical_import_status'           => 'pending',
                'historical_import_from'             => $gapStart->toDateString(),
                'historical_import_checkpoint'       => null,
                'historical_import_progress'         => null,
                'historical_import_started_at'       => null,
                'historical_import_completed_at'     => null,
                'historical_import_duration_seconds' => null,
            ]);

            $syncLog = SyncLog::create([
                'workspace_id'  => $this->workspaceId,
                'syncable_type' => AdAccount::class,
                'syncable_id'   => $account->id,
                'job_type'      => AdHistoricalImportJob::class,
                'status'        => 'queued',
                'queue'         => 'imports',
                'scheduled_at'  => now(),
            ]);

            AdHistoricalImportJob::dispatch($account->id, $this->workspaceId, $syncLog->id);
            $dispatched++;
        }

        // ── GSC properties ────────────────────────────────────────────────────
        $properties = SearchConsoleProperty::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->select(['id', 'workspace_id', 'historical_import_status'])
            ->get();

        foreach ($properties as $property) {
            if ($property->historical_import_status === null) {
                continue;
            }

            $property->update([
                'historical_import_status'           => 'pending',
                'historical_import_from'             => $gapStart->toDateString(),
                'historical_import_checkpoint'       => null,
                'historical_import_progress'         => null,
                'historical_import_started_at'       => null,
                'historical_import_completed_at'     => null,
                'historical_import_duration_seconds' => null,
            ]);

            $syncLog = SyncLog::create([
                'workspace_id'  => $this->workspaceId,
                'syncable_type' => SearchConsoleProperty::class,
                'syncable_id'   => $property->id,
                'job_type'      => GscHistoricalImportJob::class,
                'status'        => 'queued',
                'queue'         => 'imports',
                'scheduled_at'  => now(),
            ]);

            GscHistoricalImportJob::dispatch($property->id, $this->workspaceId, $syncLog->id);
            $dispatched++;
        }

        Log::info('TriggerReactivationBackfillJob: dispatched catch-up imports', [
            'workspace_id' => $this->workspaceId,
            'gap_start'    => $this->gapStart,
            'dispatched'   => $dispatched,
        ]);
    }
}
