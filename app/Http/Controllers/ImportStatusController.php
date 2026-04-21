<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\SyncLog;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Polling endpoint for the WooCommerce historical import progress.
 *
 * GET /api/stores/{id}/import-status
 *
 * Authentication:  session (web guard) — authenticated browser polling only.
 * Middleware:      auth, SetActiveWorkspace, EnforceBillingAccess (web group).
 * Workspace scope: WorkspaceScope on Store ensures the authenticated user can
 *                  only poll stores that belong to their active workspace.
 *
 * Called every 5 seconds from the onboarding progress screen.
 *
 * Response shape:
 * {
 *   "status":           "pending"|"running"|"completed"|"failed"|null,
 *   "progress":         0-100|null,
 *   "total_orders":     int|null,
 *   "started_at":       ISO 8601|null,
 *   "completed_at":     ISO 8601|null,
 *   "duration_seconds": int|null,
 *   "error_message":    string|null   -- only present on status=failed
 * }
 */
class ImportStatusController extends Controller
{
    public function __invoke(Request $request, string $storeSlug): JsonResponse
    {
        // WorkspaceScope is active (set by SetActiveWorkspace middleware).
        // A 404 here means the store does not belong to the active workspace.
        $store = Store::where('slug', $storeSlug)->first();

        if ($store === null) {
            return response()->json(['error' => 'Store not found.'], 404);
        }

        $errorMessage = null;

        if ($store->historical_import_status === 'failed') {
            // Surface the error from the most recent sync log for this import.
            $errorMessage = SyncLog::where('syncable_type', Store::class)
                ->where('syncable_id', $store->id)
                ->where('job_type', \App\Jobs\WooCommerceHistoricalImportJob::class)
                ->where('status', 'failed')
                ->latest()
                ->value('error_message');
        }

        return response()->json([
            'status'           => $store->historical_import_status,
            'progress'         => $store->historical_import_progress,
            'total_orders'     => $store->historical_import_total_orders,
            'started_at'       => $store->historical_import_started_at?->toIso8601String(),
            'completed_at'     => $store->historical_import_completed_at?->toIso8601String(),
            'duration_seconds' => $store->historical_import_duration_seconds,
            'error_message'    => $errorMessage,
        ]);
    }
}
