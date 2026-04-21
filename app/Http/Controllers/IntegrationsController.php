<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RemoveAdAccountAction;
use App\Actions\RemoveGscPropertyAction;
use App\Actions\RemoveStoreAction;
use App\Actions\StartHistoricalImportAction;
use App\Jobs\AdHistoricalImportJob;
use App\Jobs\GscHistoricalImportJob;
use App\Jobs\SyncAdInsightsJob;
use App\Jobs\SyncSearchConsoleJob;
use App\Jobs\SyncShopifyOrdersJob;
use App\Jobs\SyncStoreOrdersJob;
use App\Jobs\WooCommerceHistoricalImportJob;
use App\Models\AdAccount;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
    public function show(Request $request): Response
    {
        $workspace   = Workspace::findOrFail(app(WorkspaceContext::class)->id());
        $workspaceId = $workspace->id;

        $this->authorize('viewSettings', $workspace);

        // One query for all running sync logs in the workspace. Used below to show
        // sync-in-progress state on each row without requiring per-item queries.
        $runningSyncIds = DB::table('sync_logs')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'running')
            ->select(['syncable_type', 'syncable_id'])
            ->get()
            ->groupBy('syncable_type')
            ->map(fn ($group) => $group->pluck('syncable_id')->unique()->flip()->toArray());

        // Cache keys set at dispatch time (syncStore/syncAdAccount/syncGsc below).
        // Why: there is a gap between job dispatch and the job's handle() creating a
        // sync_log row. During that window, sync_running would wrongly flip back to false
        // on page refresh. A 5-minute cache key bridges the gap reliably.
        $queuedStoreIds     = cache()->get("sync_queued_stores_{$workspaceId}",      []);
        $queuedAdAccountIds = cache()->get("sync_queued_ad_accounts_{$workspaceId}", []);
        $queuedGscIds       = cache()->get("sync_queued_gsc_{$workspaceId}",         []);

        $storeRows = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select([
                'id', 'slug', 'name', 'domain', 'type', 'status', 'currency',
                'last_synced_at', 'historical_import_status', 'historical_import_progress',
                'historical_import_from', 'consecutive_sync_failures',
            ])
            ->orderBy('created_at')
            ->get();

        $latestWebhooks = DB::table('webhook_logs')
            ->whereIn('store_id', $storeRows->pluck('id'))
            ->selectRaw('store_id, MAX(created_at) as last_webhook_at')
            ->groupBy('store_id')
            ->pluck('last_webhook_at', 'store_id');

        $runningStoreIds      = $runningSyncIds[Store::class] ?? [];
        $runningAdAccountIds  = $runningSyncIds[AdAccount::class] ?? [];
        $runningGscIds        = $runningSyncIds[\App\Models\SearchConsoleProperty::class] ?? [];

        $stores = $storeRows->map(function (Store $s) use ($latestWebhooks, $runningStoreIds, $queuedStoreIds) {
            $lastWebhookAt = isset($latestWebhooks[$s->id])
                ? \Carbon\Carbon::parse($latestWebhooks[$s->id])
                : null;

            // Sync method: real_time if a webhook arrived in the last 90 minutes.
            $syncMethod = ($lastWebhookAt !== null && $lastWebhookAt->gte(now()->subMinutes(90)))
                ? 'real_time'
                : 'polling';

            // Data freshness: based on the most recent of last_synced_at and last_webhook_at.
            $lastSyncedAt = $s->last_synced_at;
            $mostRecentAt = match (true) {
                $lastSyncedAt !== null && $lastWebhookAt !== null => $lastSyncedAt->max($lastWebhookAt),
                $lastSyncedAt !== null                            => $lastSyncedAt,
                $lastWebhookAt !== null                           => $lastWebhookAt,
                default                                           => null,
            };

            $freshness = match (true) {
                $mostRecentAt === null                         => 'red',
                $mostRecentAt->gte(now()->subHours(2))        => 'green',
                $mostRecentAt->gte(now()->subHours(24))       => 'amber',
                default                                        => 'red',
            };

            return [
                'id'                         => $s->id,
                'slug'                       => $s->slug,
                'name'                       => $s->name,
                'domain'                     => $s->domain,
                'type'                       => $s->type,
                'status'                     => $s->status,
                'currency'                   => $s->currency,
                'last_synced_at'             => $s->last_synced_at?->toISOString(),
                'last_webhook_at'            => $lastWebhookAt?->toISOString(),
                'historical_import_status'   => $s->historical_import_status,
                'historical_import_progress' => $s->historical_import_progress,
                'historical_import_from'     => $s->historical_import_from?->toDateString(),
                'consecutive_sync_failures'  => $s->consecutive_sync_failures,
                'sync_running'               => isset($runningStoreIds[$s->id]) || in_array($s->id, $queuedStoreIds),
                // Webhook health fields — used for sync method + freshness badge on Integrations page.
                // See: PLANNING.md "Webhook health surfacing (user-facing)"
                'sync_method'                => $syncMethod,
                'freshness'                  => $freshness,
            ];
        });

        $adAccounts = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select([
                'id', 'platform', 'name', 'external_id', 'currency',
                'status', 'last_synced_at', 'consecutive_sync_failures',
                'historical_import_status', 'historical_import_progress', 'historical_import_from',
            ])
            ->orderBy('created_at')
            ->get()
            ->map(fn (AdAccount $a) => [
                'id'                         => $a->id,
                'platform'                   => $a->platform,
                'name'                       => $a->name,
                'external_id'                => $a->external_id,
                'currency'                   => $a->currency,
                'status'                     => $a->status,
                'last_synced_at'             => $a->last_synced_at?->toISOString(),
                'consecutive_sync_failures'  => $a->consecutive_sync_failures,
                'historical_import_status'   => $a->historical_import_status,
                'historical_import_progress' => $a->historical_import_progress,
                'historical_import_from'     => $a->historical_import_from?->toDateString(),
                'sync_running'               => isset($runningAdAccountIds[$a->id]) || in_array($a->id, $queuedAdAccountIds),
            ]);

        $gscProperties = SearchConsoleProperty::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select(['id', 'property_url', 'status', 'last_synced_at', 'consecutive_sync_failures',
                      'historical_import_status', 'historical_import_progress', 'historical_import_from'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (SearchConsoleProperty $p) => [
                'id'                         => $p->id,
                'property_url'               => $p->property_url,
                'status'                     => $p->status,
                'last_synced_at'             => $p->last_synced_at?->toISOString(),
                'consecutive_sync_failures'  => $p->consecutive_sync_failures,
                'historical_import_status'   => $p->historical_import_status,
                'historical_import_progress' => $p->historical_import_progress,
                'historical_import_from'     => $p->historical_import_from?->toDateString(),
                'sync_running'               => isset($runningGscIds[$p->id]) || in_array($p->id, $queuedGscIds),
            ]);

        $userRole = WorkspaceUser::where('workspace_id', $workspaceId)
            ->where('user_id', $request->user()->id)
            ->value('role') ?? 'member';

        // Pending pickers — tokens live in cache so they survive the cross-domain OAuth redirect.
        $gscPending  = $this->resolvePending($request->query('gsc_pending'),  $workspaceId, 'properties');
        $fbPending   = $this->resolvePending($request->query('fb_pending'),   $workspaceId, 'accounts');
        $gadsPending = $this->resolvePending($request->query('gads_pending'), $workspaceId, 'accounts');

        // oauth_error + oauth_platform are passed as query params because cross-domain
        // OAuth redirects (ngrok → app domain) do not carry the session cookie.
        // We pass them straight to Inertia so the frontend renders them inline — no flash,
        // no toast, so the message doesn't persist across page refreshes.
        $oauthError    = $request->query('oauth_error');
        $oauthPlatform = $request->query('oauth_platform');

        return Inertia::render('Settings/Integrations', [
            'stores'         => $stores,
            'ad_accounts'    => $adAccounts,
            'gsc_properties' => $gscProperties,
            'user_role'      => $userRole,
            'gsc_pending'    => $gscPending,
            'fb_pending'     => $fbPending,
            'gads_pending'   => $gadsPending,
            'oauth_error'    => is_string($oauthError) && $oauthError !== '' ? $oauthError : null,
            'oauth_platform' => is_string($oauthPlatform) && $oauthPlatform !== '' ? $oauthPlatform : null,
        ]);
    }

    /**
     * Read a pending OAuth cache entry and return the key + payload field for the frontend.
     *
     * @return array{key: string, items: mixed}|null
     */
    private function resolvePending(mixed $key, int $workspaceId, string $field): ?array
    {
        if (! is_string($key) || $key === '') {
            return null;
        }

        $cached = cache()->get($key);

        if ($cached === null || (int) ($cached['workspace_id'] ?? 0) !== $workspaceId) {
            return null;
        }

        return ['key' => $key, 'items' => $cached[$field]];
    }

    /**
     * Permanently remove a store and all its data.
     */
    public function removeStore(Request $request, string $storeSlug): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $store = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('slug', $storeSlug)
            ->firstOrFail();

        $this->authorize('delete', $store); // StorePolicy::delete = owner or admin

        $name = $store->name;
        (new RemoveStoreAction)->handle($store);

        return redirect()->route('settings.integrations', ['workspace' => $workspace->slug])
            ->with('success', "{$name} removed.");
    }

    /**
     * Permanently remove a Facebook or Google Ads ad account and all its data.
     */
    public function removeAdAccount(Request $request, string $adAccountId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $adAccount = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $adAccountId)
            ->firstOrFail();

        $this->authorize('update', $workspace); // owner or admin

        $name     = ucfirst($adAccount->platform) . ' — ' . $adAccount->name;
        (new RemoveAdAccountAction)->handle($adAccount);

        return redirect()->route('settings.integrations')
            ->with('success', "{$name} removed.");
    }

    /**
     * Permanently remove a Google Search Console property and all its data.
     */
    public function removeGsc(Request $request, string $propertyId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $property = SearchConsoleProperty::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $propertyId)
            ->firstOrFail();

        $this->authorize('update', $workspace); // owner or admin

        $url = $property->property_url;
        (new RemoveGscPropertyAction)->handle($property);

        return redirect()->route('settings.integrations')
            ->with('success', "Search Console property {$url} removed.");
    }

    /**
     * Retry a failed historical import for a store.
     * Preserves the existing checkpoint so the job resumes from where it failed.
     */
    public function retryImportStore(Request $request, string $storeSlug): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $store = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('slug', $storeSlug)
            ->firstOrFail();

        $this->authorize('delete', $store);

        abort_unless($store->historical_import_status === 'failed', 422, 'Import is not in a failed state.');

        $store->update(['historical_import_status' => 'pending']);

        $syncLog = SyncLog::create([
            'workspace_id'  => $workspace->id,
            'syncable_type' => Store::class,
            'syncable_id'   => $store->id,
            'job_type'      => WooCommerceHistoricalImportJob::class,
            'status'        => 'queued',
        ]);

        WooCommerceHistoricalImportJob::dispatch($store->id, $workspace->id, $syncLog->id);

        return back()->with('success', "Import retry queued for {$store->name}.");
    }

    /**
     * Retry a failed historical import for a Facebook or Google Ads account.
     * Preserves the existing checkpoint so the job resumes from where it failed.
     */
    public function retryImportAdAccount(Request $request, string $adAccountId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $adAccount = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $adAccountId)
            ->firstOrFail();

        $this->authorize('update', $workspace);

        abort_unless($adAccount->historical_import_status === 'failed', 422, 'Import is not in a failed state.');

        $adAccount->update(['historical_import_status' => 'pending']);

        $syncLog = SyncLog::create([
            'workspace_id'  => $workspace->id,
            'syncable_type' => AdAccount::class,
            'syncable_id'   => $adAccount->id,
            'job_type'      => AdHistoricalImportJob::class,
            'status'        => 'queued',
        ]);

        AdHistoricalImportJob::dispatch($adAccount->id, $workspace->id, $syncLog->id);

        return back()->with('success', "Import retry queued for {$adAccount->name}.");
    }

    /**
     * Retry a failed historical import for a Search Console property.
     * Preserves the existing checkpoint so the job resumes from where it failed.
     */
    public function retryImportGsc(Request $request, string $propertyId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $property = SearchConsoleProperty::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $propertyId)
            ->firstOrFail();

        $this->authorize('update', $workspace);

        abort_unless($property->historical_import_status === 'failed', 422, 'Import is not in a failed state.');

        $property->update(['historical_import_status' => 'pending']);

        $syncLog = SyncLog::create([
            'workspace_id'  => $workspace->id,
            'syncable_type' => SearchConsoleProperty::class,
            'syncable_id'   => $property->id,
            'job_type'      => GscHistoricalImportJob::class,
            'status'        => 'queued',
        ]);

        GscHistoricalImportJob::dispatch($property->id, $workspace->id, $syncLog->id);

        return back()->with('success', "Import retry queued for {$property->property_url}.");
    }

    /**
     * Re-import a store's history from a user-chosen date, discarding any existing
     * checkpoint so the job starts fresh from that date.
     */
    public function reimportStore(Request $request, string $storeSlug, StartHistoricalImportAction $importAction): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $store = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('slug', $storeSlug)
            ->firstOrFail();

        $this->authorize('delete', $store);

        $validated = $request->validate([
            'from_date' => ['nullable', 'date', 'before:today'],
        ]);

        // Reset import state before re-dispatching.
        $store->update([
            'status'                         => 'active',
            'consecutive_sync_failures'      => 0,
            'historical_import_checkpoint'   => null,
            'historical_import_completed_at' => null,
        ]);

        $fromDate = isset($validated['from_date'])
            ? \Carbon\Carbon::parse($validated['from_date'])
            : \Carbon\Carbon::createFromDate(2010, 1, 1);

        $importAction->handle($store, $fromDate);

        $fromLabel = $validated['from_date'] ?? 'the beginning';

        return back()->with('success', "Re-import queued for {$store->name} from {$fromLabel}.");
    }

    /**
     * Re-import an ad account's history from a user-chosen date.
     */
    public function reimportAdAccount(Request $request, string $adAccountId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $adAccount = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $adAccountId)
            ->firstOrFail();

        $this->authorize('update', $workspace);

        // Why: Facebook API limits historical data to 37 months back.
        // Requests beyond that return error #3018.
        $earliestAllowed = now()->subMonths(37)->toDateString();

        $validated = $request->validate([
            'from_date' => ['nullable', 'date', 'before:today', "after_or_equal:{$earliestAllowed}"],
        ]);

        $adAccount->update([
            'historical_import_status'       => 'pending',
            'historical_import_from'         => $validated['from_date'] ?? null,
            'historical_import_checkpoint'   => null,
            'historical_import_progress'     => null,
        ]);

        $syncLog = SyncLog::create([
            'workspace_id'  => $workspace->id,
            'syncable_type' => AdAccount::class,
            'syncable_id'   => $adAccount->id,
            'job_type'      => AdHistoricalImportJob::class,
            'status'        => 'queued',
        ]);

        AdHistoricalImportJob::dispatch($adAccount->id, $workspace->id, $syncLog->id);

        $fromLabel = $validated['from_date'] ?? 'the beginning';

        return back()->with('success', "Re-import queued for {$adAccount->name} from {$fromLabel}.");
    }

    /**
     * Re-import a Search Console property's history from a user-chosen date.
     */
    public function reimportGsc(Request $request, string $propertyId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $property = SearchConsoleProperty::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $propertyId)
            ->firstOrFail();

        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'from_date' => ['nullable', 'date', 'before:today'],
        ]);

        $property->update([
            'historical_import_status'     => 'pending',
            'historical_import_from'       => $validated['from_date'] ?? null,
            'historical_import_checkpoint' => null,
            'historical_import_progress'   => null,
        ]);

        $syncLog = SyncLog::create([
            'workspace_id'  => $workspace->id,
            'syncable_type' => SearchConsoleProperty::class,
            'syncable_id'   => $property->id,
            'job_type'      => GscHistoricalImportJob::class,
            'status'        => 'queued',
        ]);

        GscHistoricalImportJob::dispatch($property->id, $workspace->id, $syncLog->id);

        $fromLabel = $validated['from_date'] ?? 'the beginning';

        return back()->with('success', "Re-import queued for {$property->property_url} from {$fromLabel}.");
    }

    /**
     * Manually trigger an order sync for a single store.
     * Routes to the platform-specific sync job; bypasses the webhook-active check.
     */
    public function syncStore(Request $request, string $storeSlug): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $store = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('slug', $storeSlug)
            ->firstOrFail();

        $this->authorize('delete', $store); // StorePolicy — owner or admin

        // Reset error state so the job doesn't silently exit on the status !== 'active' guard.
        if ($store->status === 'error') {
            $store->update([
                'status'                    => 'active',
                'consecutive_sync_failures' => 0,
            ]);
        }

        if ($store->platform === 'shopify') {
            dispatch(new SyncShopifyOrdersJob($store->id, $store->workspace_id, force: true));
        } else {
            dispatch(new SyncStoreOrdersJob($store->id, $store->workspace_id, force: true));
        }

        $key     = "sync_queued_stores_{$workspace->id}";
        $current = cache()->get($key, []);
        if (! in_array($store->id, $current)) {
            cache()->put($key, [...$current, $store->id], now()->addMinutes(5));
        }

        return back()->with('success', "Sync queued for {$store->name}.");
    }

    /**
     * Manually trigger an ad insights sync for a single ad account.
     */
    public function syncAdAccount(Request $request, string $adAccountId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $adAccount = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $adAccountId)
            ->firstOrFail();

        $this->authorize('update', $workspace); // owner or admin

        // A manual sync is an explicit user-initiated retry. Reset error state so the job
        // doesn't silently exit on the status !== 'active' guard in SyncAdInsightsJob.
        if ($adAccount->status === 'error') {
            $adAccount->update([
                'status'                    => 'active',
                'consecutive_sync_failures' => 0,
            ]);
        }

        dispatch(new SyncAdInsightsJob($adAccount->id, $workspace->id, $adAccount->platform));

        $key     = "sync_queued_ad_accounts_{$workspace->id}";
        $current = cache()->get($key, []);
        if (! in_array($adAccount->id, $current)) {
            cache()->put($key, [...$current, $adAccount->id], now()->addMinutes(5));
        }

        return back()->with('success', "Sync queued for {$adAccount->name}.");
    }

    /**
     * Manually trigger a Search Console sync for a single property.
     */
    public function syncGsc(Request $request, string $propertyId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $property = SearchConsoleProperty::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $propertyId)
            ->firstOrFail();

        $this->authorize('update', $workspace); // owner or admin

        dispatch(new SyncSearchConsoleJob($property->id, $workspace->id));

        $key     = "sync_queued_gsc_{$workspace->id}";
        $current = cache()->get($key, []);
        if (! in_array($property->id, $current)) {
            cache()->put($key, [...$current, $property->id], now()->addMinutes(5));
        }

        return back()->with('success', "Sync queued for {$property->property_url}.");
    }
}
