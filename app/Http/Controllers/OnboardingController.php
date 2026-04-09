<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConnectStoreAction;
use App\Actions\CreateWorkspaceAction;
use App\Actions\StartHistoricalImportAction;
use App\Exceptions\WooCommerceConnectionException;
use App\Models\Store;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles the multi-step onboarding flow for new users.
 *
 * Routes (all require auth + verified, SetActiveWorkspace skips 'onboarding/*'):
 *   GET  /onboarding         → show()         — detects current step from DB state
 *   POST /onboarding/store   → connectStore() — creates workspace + connects WooCommerce store
 *   POST /onboarding/import  → startImport()  — records date range + dispatches import job
 *
 * Step detection logic in show():
 *   1 — no workspace or no store
 *   2 — store connected, historical_import_status IS NULL (import not yet started)
 *   3 — historical_import_status IN (pending, running, failed)
 *   redirect /dashboard — historical_import_status = completed
 *
 * Invitation path: VerifyEmailController handles the invitation token and redirects
 * directly to /dashboard — the onboarding flow is never reached for invited users.
 */
class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        $workspaceUser = WorkspaceUser::where('user_id', $user->id)
            ->whereHas('workspace', fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('created_at')
            ->first();

        if ($workspaceUser === null) {
            return Inertia::render('Onboarding/Index', ['step' => 1]);
        }

        // Workspace exists — load without WorkspaceScope (context not set on onboarding path)
        $workspace = Workspace::find($workspaceUser->workspace_id);

        $store = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->orderBy('created_at')
            ->first();

        if ($store === null) {
            return Inertia::render('Onboarding/Index', ['step' => 1]);
        }

        $importStatus = $store->historical_import_status;

        if ($importStatus === 'completed' && ! $request->boolean('add_store')) {
            return redirect()->route('dashboard');
        }

        if ($importStatus === 'completed') {
            return Inertia::render('Onboarding/Index', ['step' => 1]);
        }

        if ($importStatus === null) {
            return Inertia::render('Onboarding/Index', [
                'step'       => 2,
                'store_id'   => $store->id,
                'store_name' => $store->name,
            ]);
        }

        // pending | running | failed → show progress screen
        return Inertia::render('Onboarding/Index', [
            'step'       => 3,
            'store_id'   => $store->id,
            'store_slug' => $store->slug,
        ]);
    }

    /**
     * Validate WooCommerce credentials, auto-create workspace if needed, connect store.
     */
    public function connectStore(
        Request $request,
        CreateWorkspaceAction $create,
        ConnectStoreAction $connect,
    ): RedirectResponse {
        $validated = $request->validate([
            'domain'          => 'required|string|max:255',
            'consumer_key'    => 'required|string|max:500',
            'consumer_secret' => 'required|string|max:500',
        ]);

        $user = $request->user();

        // Reuse existing workspace if the user somehow already has one (edge case: re-entry)
        $existingWorkspaceUser = WorkspaceUser::where('user_id', $user->id)
            ->whereHas('workspace', fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('created_at')
            ->first();

        $workspace = $existingWorkspaceUser !== null
            ? Workspace::find($existingWorkspaceUser->workspace_id)
            : $create->handle($user, $validated['domain']);

        try {
            $store = $connect->handle($workspace, $validated);
        } catch (WooCommerceConnectionException $e) {
            return back()->withErrors(['domain' => $e->getMessage()]);
        }

        // Rename the workspace to the WooCommerce site title now that we have it.
        // This also fixes the case where a previous failed attempt left a stale domain name.
        $newSlug = $create->generateUniqueSlug($store->name, $workspace->id);
        $workspace->update(['name' => $store->name, 'slug' => $newSlug]);

        // Set active workspace in session so subsequent polling requests work
        session(['active_workspace_id' => $workspace->id]);

        return redirect()->route('onboarding');
    }

    /**
     * Full start-over: delete the store (and clear session), return to step 1.
     *
     * Safe to call at any point during onboarding (step 2 or 3).
     * The workspace is kept — connectStore() reuses it on the next attempt.
     */
    public function resetOnboarding(Request $request): RedirectResponse
    {
        $user = $request->user();

        $store = Store::withoutGlobalScopes()
            ->whereHas('workspace', fn ($q) => $q
                ->whereNull('deleted_at')
                ->whereHas('workspaceUsers', fn ($q) => $q->where('user_id', $user->id))
            )
            ->orderBy('created_at')
            ->first();

        if ($store !== null) {
            $store->delete();
        }

        session()->forget('active_workspace_id');

        return redirect()->route('onboarding');
    }

    /**
     * Reset a failed import so the user can choose a date range again.
     */
    public function resetImport(Request $request): RedirectResponse
    {
        $user = $request->user();

        $store = Store::withoutGlobalScopes()
            ->whereHas('workspace', fn ($q) => $q
                ->whereNull('deleted_at')
                ->whereHas('workspaceUsers', fn ($q) => $q->where('user_id', $user->id))
            )
            ->where('historical_import_status', 'failed')
            ->orderBy('created_at')
            ->firstOrFail();

        $store->update([
            'historical_import_status'     => null,
            'historical_import_checkpoint' => null,
            'historical_import_progress'   => null,
        ]);

        return redirect()->route('onboarding');
    }

    /**
     * Record the chosen import date range and dispatch the historical import job.
     */
    public function startImport(
        Request $request,
        StartHistoricalImportAction $action,
    ): RedirectResponse {
        $validated = $request->validate([
            'store_id' => 'required|integer',
            'period'   => 'required|in:30days,90days,1year,all',
        ]);

        $user = $request->user();

        // Verify the store belongs to the authenticated user — without WorkspaceScope
        $store = Store::withoutGlobalScopes()
            ->where('id', $validated['store_id'])
            ->whereHas('workspace', fn ($q) => $q
                ->whereNull('deleted_at')
                ->whereHas('workspaceUsers', fn ($q) => $q->where('user_id', $user->id))
            )
            ->firstOrFail();

        $fromDate = match ($validated['period']) {
            '30days' => now()->subDays(30),
            '90days' => now()->subDays(90),
            '1year'  => now()->subYear(),
            'all'    => Carbon::createFromDate(2010, 1, 1),
        };

        $action->handle($store, $fromDate);

        return redirect()->route('onboarding');
    }
}
