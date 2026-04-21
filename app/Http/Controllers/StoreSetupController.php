<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConnectStoreAction;
use App\Actions\StartHistoricalImportAction;
use App\Exceptions\WooCommerceConnectionException;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles the in-app multi-step flow for adding a store to an existing workspace.
 *
 * This is the authenticated-user counterpart to OnboardingController. It lives under
 * the workspace-prefixed route group (/{workspace}/stores/connect) so WorkspaceContext
 * is already set by SetActiveWorkspace middleware — no manual workspace resolution needed.
 *
 * Routes:
 *   GET  /{workspace}/stores/connect          → show()        — step detection from DB state
 *   POST /{workspace}/stores/connect          → connect()     — WooCommerce credential validation
 *   POST /{workspace}/stores/connect/country  → saveCountry() — primary country for the store
 *   POST /{workspace}/stores/connect/import   → startImport() — date range + dispatches import job
 *   POST /{workspace}/stores/connect/reset    → reset()       — delete in-progress store, back to step 1
 *
 * Step detection in show():
 *   1 — store connection form (WooCommerce / Shopify)
 *   2 — country prompt (store connected, country not yet confirmed this session)
 *   3 — import date range picker (country confirmed)
 *   After import starts → redirect to stores.index (inline ImportBadge shows progress)
 *
 * Country tracking uses the same session key pattern as OnboardingController
 * ('onboarding_country_seen_{store_id}') so the country prompt only appears once per session.
 *
 * @see App\Http\Controllers\OnboardingController (same step logic for the new-user path)
 * @see PLANNING.md section 6 (attribution setup, country detection)
 */
class StoreSetupController extends Controller
{
    /**
     * Detect the current step from DB state and render the appropriate form.
     *
     * Targets the newest store that has never completed its first import
     * (historical_import_completed_at IS NULL). If no such store exists,
     * we're at step 1 — show the connection form.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $slug        = app(WorkspaceContext::class)->slug();

        // Find the newest store that hasn't yet finished its first import.
        $store = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereNull('historical_import_completed_at')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($store !== null) {
            $importStatus   = $store->historical_import_status;
            $countrySeenKey = 'onboarding_country_seen_' . $store->id;
            $countryDone    = session()->has($countrySeenKey);

            // Import is running or queued — send to Stores/Index where the inline badge shows progress.
            if ($importStatus === 'pending' || $importStatus === 'running') {
                return redirect()->route('stores.index', ['workspace' => $slug]);
            }

            // Import failed — send to Integrations where the "Retry import" action lives.
            if ($importStatus === 'failed') {
                return redirect()->route('settings.integrations', ['workspace' => $slug]);
            }

            // import_status IS NULL: store connected, prompt for country if not yet done.
            if (! $countryDone) {
                return Inertia::render('Stores/Connect', [
                    'step'                => 2,
                    'store_id'            => $store->id,
                    'store_name'          => $store->name,
                    'website_url'         => $store->website_url,
                    'country'             => $store->primary_country_code,
                    'ip_detected_country' => session('ip_detected_country'),
                ]);
            }

            // Country confirmed — choose import date range.
            return Inertia::render('Stores/Connect', [
                'step'       => 3,
                'store_id'   => $store->id,
                'store_name' => $store->name,
            ]);
        }

        // Step 1: no in-progress store — show the connection form.
        return Inertia::render('Stores/Connect', [
            'step' => 1,
        ]);
    }

    /**
     * Validate WooCommerce credentials and connect the store to the active workspace.
     *
     * Workspace is resolved from WorkspaceContext (set by SetActiveWorkspace) rather than
     * the session — this route is workspace-prefixed so the correct tenant is guaranteed.
     * On the first store connection, renames the workspace to the WooCommerce site title
     * (matching OnboardingController::connectStore()). Subsequent stores leave the name alone.
     */
    public function connect(
        Request $request,
        ConnectStoreAction $connect,
    ): RedirectResponse {
        $validated = $request->validate([
            'domain'          => 'required|string|max:255',
            'consumer_key'    => 'required|string|max:500',
            'consumer_secret' => 'required|string|max:500',
        ]);

        $workspaceId  = app(WorkspaceContext::class)->id();
        $slug         = app(WorkspaceContext::class)->slug();
        $workspace    = Workspace::withoutGlobalScopes()->findOrFail($workspaceId);
        $isFirstStore = ! Store::withoutGlobalScopes()->where('workspace_id', $workspaceId)->exists();

        try {
            $store = $connect->handle($workspace, $validated);
        } catch (WooCommerceConnectionException $e) {
            return back()->withErrors(['domain' => $e->getMessage()]);
        }

        if ($isFirstStore) {
            $workspace->update(['name' => $store->name]);
        }

        return redirect()->route('stores.connect', ['workspace' => $slug]);
    }

    /**
     * Save (or skip) the store's primary country code.
     *
     * Uses the same session-flag pattern as OnboardingController::saveCountry() so the
     * country prompt only appears once per session. Posting null/empty writes NULL explicitly.
     *
     * @see PLANNING.md section 5.7
     */
    public function saveCountry(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_id'     => 'required|integer',
            'country_code' => 'nullable|string|size:2|alpha',
        ]);

        $slug  = app(WorkspaceContext::class)->slug();
        $store = $this->resolveStore($validated['store_id']);

        $store->update([
            'primary_country_code' => isset($validated['country_code']) && $validated['country_code'] !== ''
                ? strtoupper($validated['country_code'])
                : null,
        ]);

        session(['onboarding_country_seen_' . $store->id => true]);

        return redirect()->route('stores.connect', ['workspace' => $slug]);
    }

    /**
     * Record the chosen import date range and dispatch the historical import job.
     *
     * After dispatch, the user is sent to Stores/Index. An inline ImportBadge on that
     * page polls /api/stores/{slug}/import-status and updates in real time — no
     * dedicated progress screen needed for the in-app add-store path.
     */
    public function startImport(
        Request $request,
        StartHistoricalImportAction $action,
    ): RedirectResponse {
        $validated = $request->validate([
            'store_id' => 'required|integer',
            'period'   => 'required|in:30days,90days,1year,all',
        ]);

        $slug  = app(WorkspaceContext::class)->slug();
        $store = $this->resolveStore($validated['store_id']);

        $fromDate = match ($validated['period']) {
            '30days' => now()->subDays(30),
            '90days' => now()->subDays(90),
            '1year'  => now()->subYear(),
            'all'    => Carbon::createFromDate(2010, 1, 1),
        };

        $action->handle($store, $fromDate);

        return redirect()->route('stores.index', ['workspace' => $slug]);
    }

    /**
     * Delete the newest in-progress store and return to step 1.
     *
     * Only deletes stores that have never completed a first import
     * (historical_import_completed_at IS NULL) — completed stores are never touched.
     */
    public function reset(Request $request): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $slug        = app(WorkspaceContext::class)->slug();

        $store = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereNull('historical_import_completed_at')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($store !== null) {
            $store->delete();
        }

        return redirect()->route('stores.connect', ['workspace' => $slug]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a store by ID, verifying it belongs to the active workspace.
     */
    private function resolveStore(int $storeId): Store
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        return Store::withoutGlobalScopes()
            ->where('id', $storeId)
            ->where('workspace_id', $workspaceId)
            ->firstOrFail();
    }
}
