<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RemoveAdAccountAction;
use App\Actions\RemoveGscPropertyAction;
use App\Actions\RemoveStoreAction;
use App\Jobs\SyncAdInsightsJob;
use App\Jobs\SyncSearchConsoleJob;
use App\Jobs\SyncStoreOrdersJob;
use App\Models\AdAccount;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
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

        $storeRows = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select([
                'id', 'slug', 'name', 'domain', 'type', 'status', 'currency',
                'last_synced_at', 'historical_import_status', 'consecutive_sync_failures',
            ])
            ->orderBy('created_at')
            ->get();

        $latestWebhooks = DB::table('webhook_logs')
            ->whereIn('store_id', $storeRows->pluck('id'))
            ->selectRaw('store_id, MAX(created_at) as last_webhook_at')
            ->groupBy('store_id')
            ->pluck('last_webhook_at', 'store_id');

        $stores = $storeRows->map(fn (Store $s) => [
            'id'                        => $s->id,
            'slug'                      => $s->slug,
            'name'                      => $s->name,
            'domain'                    => $s->domain,
            'type'                      => $s->type,
            'status'                    => $s->status,
            'currency'                  => $s->currency,
            'last_synced_at'            => $s->last_synced_at?->toISOString(),
            'last_webhook_at'           => isset($latestWebhooks[$s->id])
                ? \Carbon\Carbon::parse($latestWebhooks[$s->id])->toISOString()
                : null,
            'historical_import_status'  => $s->historical_import_status,
            'consecutive_sync_failures' => $s->consecutive_sync_failures,
        ]);

        $adAccounts = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select([
                'id', 'platform', 'name', 'external_id', 'currency',
                'status', 'last_synced_at', 'consecutive_sync_failures',
            ])
            ->orderBy('created_at')
            ->get()
            ->map(fn (AdAccount $a) => [
                'id'                        => $a->id,
                'platform'                  => $a->platform,
                'name'                      => $a->name,
                'external_id'               => $a->external_id,
                'currency'                  => $a->currency,
                'status'                    => $a->status,
                'last_synced_at'            => $a->last_synced_at?->toISOString(),
                'consecutive_sync_failures' => $a->consecutive_sync_failures,
            ]);

        $gscProperties = SearchConsoleProperty::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select(['id', 'property_url', 'status', 'last_synced_at', 'consecutive_sync_failures'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (SearchConsoleProperty $p) => [
                'id'                        => $p->id,
                'property_url'              => $p->property_url,
                'status'                    => $p->status,
                'last_synced_at'            => $p->last_synced_at?->toISOString(),
                'consecutive_sync_failures' => $p->consecutive_sync_failures,
            ]);

        $userRole = WorkspaceUser::where('workspace_id', $workspaceId)
            ->where('user_id', $request->user()->id)
            ->value('role') ?? 'member';

        // Pending pickers — tokens live in cache so they survive the cross-domain OAuth redirect.
        $gscPending = $this->resolvePending($request->query('gsc_pending'), $workspaceId, 'properties');
        $fbPending  = $this->resolvePending($request->query('fb_pending'),  $workspaceId, 'accounts');
        $gadsPending = $this->resolvePending($request->query('gads_pending'), $workspaceId, 'accounts');

        return Inertia::render('Settings/Integrations', [
            'stores'         => $stores,
            'ad_accounts'    => $adAccounts,
            'gsc_properties' => $gscProperties,
            'user_role'      => $userRole,
            'gsc_pending'    => $gscPending,
            'fb_pending'     => $fbPending,
            'gads_pending'   => $gadsPending,
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

        return redirect()->route('settings.integrations')
            ->with('success', "{$name} removed.");
    }

    /**
     * Permanently remove a Facebook or Google Ads ad account and all its data.
     */
    public function removeAdAccount(Request $request, int $adAccountId): RedirectResponse
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
    public function removeGsc(Request $request, int $propertyId): RedirectResponse
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
     * Manually trigger a WooCommerce order sync for a single store.
     * Bypasses the webhook-active check so the sync always runs.
     */
    public function syncStore(Request $request, string $storeSlug): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $store = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('slug', $storeSlug)
            ->firstOrFail();

        $this->authorize('delete', $store); // StorePolicy — owner or admin

        dispatch(new SyncStoreOrdersJob($store->id, $store->workspace_id, force: true));

        return back()->with('success', "Sync queued for {$store->name}.");
    }

    /**
     * Manually trigger an ad insights sync for a single ad account.
     */
    public function syncAdAccount(Request $request, int $adAccountId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $adAccount = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $adAccountId)
            ->firstOrFail();

        $this->authorize('update', $workspace); // owner or admin

        dispatch(new SyncAdInsightsJob($adAccount->id, $workspace->id));

        return back()->with('success', "Sync queued for {$adAccount->name}.");
    }

    /**
     * Manually trigger a Search Console sync for a single property.
     */
    public function syncGsc(Request $request, int $propertyId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $property = SearchConsoleProperty::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $propertyId)
            ->firstOrFail();

        $this->authorize('update', $workspace); // owner or admin

        dispatch(new SyncSearchConsoleJob($property->id, $workspace->id));

        return back()->with('success', "Sync queued for {$property->property_url}.");
    }
}
