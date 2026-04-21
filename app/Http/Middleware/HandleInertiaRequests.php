<?php

namespace App\Http\Middleware;

use App\Models\AdAccount;
use App\Models\Alert;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Shares workspace context for AppLayout (workspace switcher, alert bell, user menu).
     * WorkspaceContext is set by SetActiveWorkspace middleware before this runs during
     * the response phase. On paths where workspace resolution is skipped (onboarding,
     * etc.) workspaceId will be null and workspace props are omitted.
     *
     * Alert uses WorkspaceScope — queried with withoutGlobalScopes() + explicit filter
     * to avoid the RuntimeException thrown when WorkspaceContext is unset.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user        = $request->user();
        $workspaceId = app(WorkspaceContext::class)->id();

        $workspace = null;
        $workspaces = null;
        $stores = null;
        $unreadAlertsCount = 0;

        if ($user && $workspaceId) {
            $workspace = Workspace::select([
                'id', 'name', 'slug', 'reporting_currency', 'reporting_timezone',
                'trial_ends_at', 'billing_plan', 'pm_type',
                'has_store', 'has_ads', 'has_gsc', 'has_psi',
            ])->find($workspaceId);

            $workspaces = WorkspaceUser::where('user_id', $user->id)
                ->whereHas('workspace', fn ($q) => $q->whereNull('deleted_at'))
                ->with(['workspace' => fn ($q) => $q->select([
                    'id', 'name', 'slug', 'reporting_currency', 'reporting_timezone',
                    'trial_ends_at', 'billing_plan',
                ])])
                ->get()
                ->pluck('workspace')
                ->filter()
                ->values();

            $stores = Store::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->select(['id', 'slug', 'name', 'status', 'last_synced_at'])
                ->orderBy('created_at')
                ->get();

            $unreadAlertsCount = Alert::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereNull('read_at')
                ->whereNull('resolved_at')
                ->count();

            $workspaceRole = WorkspaceUser::where('workspace_id', $workspaceId)
                ->where('user_id', $user->id)
                ->value('role');

            // Why: "All time" should start at the first real data record across all
            // connected sources. We check orders, ad insights, and GSC stats and take
            // the oldest date so the range covers every data point we have.
            // A 1-day buffer before the earliest record gives charts a clean left edge.
            $earliestOrderDate = DB::table('orders')
                ->where('workspace_id', $workspaceId)
                ->min(DB::raw("DATE(occurred_at)"));

            // Why: ad_insights must always be filtered by a single level to avoid
            // cross-level duplication. Ad-level rows may pre-date campaign-level rows,
            // which would produce an incorrect "All time" range start.
            $earliestAdDate = DB::table('ad_insights')
                ->where('workspace_id', $workspaceId)
                ->where('level', 'campaign')
                ->whereNull('hour')
                ->min('date');

            $earliestGscDate = DB::table('gsc_daily_stats')
                ->where('workspace_id', $workspaceId)
                ->min('date');

            $candidates = array_filter([$earliestOrderDate, $earliestAdDate, $earliestGscDate]);
            $earliest   = $candidates ? min($candidates) : null;

            // Add 1-day buffer so the chart left edge doesn't clip the first data point
            if ($earliest) {
                $earliest = Carbon::parse($earliest)->subDay()->toDateString();
            }

            // Integration freshness — compact array for <DataFreshness /> component.
            // Each row: label, status, last_synced_at (ISO string or null).
            // Store rows come from the already-loaded $stores collection to avoid
            // an extra query. Ad accounts and GSC each make one lightweight query.
            // @see PLANNING.md section 14.2
            $integrationsFreshness = [];

            foreach ($stores as $store) {
                $integrationsFreshness[] = [
                    'label'                      => $store->name,
                    'type'                       => 'store',
                    'status'                     => $store->status,
                    'last_synced_at'             => $store->last_synced_at?->toIso8601String(),
                    'consecutive_sync_failures'  => $store->consecutive_sync_failures ?? 0,
                ];
            }

            // One row per ad platform — use the most-recently-synced account
            $adAccountsByPlatform = AdAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->select(['platform', 'status', 'last_synced_at', 'consecutive_sync_failures'])
                ->orderByDesc('last_synced_at')
                ->get()
                ->groupBy('platform');

            $platformLabels = ['facebook' => 'Facebook', 'google' => 'Google Ads'];
            foreach ($platformLabels as $platform => $label) {
                if ($adAccountsByPlatform->has($platform)) {
                    $platformAccounts = $adAccountsByPlatform[$platform];
                    $best = $platformAccounts->first();

                    // Surface token_expired if any account for this platform has expired.
                    $hasTokenExpired = $platformAccounts->contains('status', 'token_expired');

                    $integrationsFreshness[] = [
                        'label'                      => $label,
                        'type'                       => 'ad_account',
                        'platform'                   => $platform,
                        'status'                     => $hasTokenExpired ? 'token_expired' : $best->status,
                        'last_synced_at'             => $best->last_synced_at?->toIso8601String(),
                        'consecutive_sync_failures'  => (int) ($platformAccounts->max('consecutive_sync_failures') ?? 0),
                    ];
                }
            }

            // GSC — one row covering all properties; most-recently-synced wins.
            // consecutive_sync_failures: MAX across properties so any failing one surfaces.
            // historical_import_status: 'failed' if any property's import failed and
            // hasn't been superseded by a completed run.
            $gscProperty = SearchConsoleProperty::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('last_synced_at')
                ->select(['status', 'last_synced_at', 'consecutive_sync_failures', 'historical_import_status'])
                ->first();

            if ($gscProperty) {
                $maxGscFailures = SearchConsoleProperty::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->max('consecutive_sync_failures') ?? 0;

                $hasFailedImport = SearchConsoleProperty::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->where('historical_import_status', 'failed')
                    ->exists();

                // If any property has an expired token, surface that over the most-recently-synced status.
                $hasTokenExpired = SearchConsoleProperty::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->where('status', 'token_expired')
                    ->exists();

                $integrationsFreshness[] = [
                    'label'                      => 'Search Console',
                    'type'                       => 'gsc',
                    'status'                     => $hasTokenExpired ? 'token_expired' : $gscProperty->status,
                    'last_synced_at'             => $gscProperty->last_synced_at?->toIso8601String(),
                    'consecutive_sync_failures'  => (int) $maxGscFailures,
                    'historical_import_status'   => $hasFailedImport ? 'failed' : $gscProperty->historical_import_status,
                ];
            }
        }

        return [
            ...parent::share($request),
            'auth' => [
                // view_preferences shared so BreakdownView + other components can
                // restore per-view UI state (breakdown mode, sort, filter chip) on first load.
                // See: PLANNING.md "view_preferences JSONB" and BreakdownView component.
                'user' => $user ? array_merge($user->only(['id', 'name', 'email', 'email_verified_at', 'is_super_admin', 'last_login_at']), [
                    'view_preferences' => $user->view_preferences ?? [],
                ]) : null,
            ],
            'flash' => [
                'success' => session('success'),
                'error'   => session('error'),
            ],
            'workspace'               => $workspace,
            'workspaces'              => $workspaces,
            'stores'                  => $stores,
            'unread_alerts_count'     => $unreadAlertsCount,
            'workspace_role'          => $workspaceRole ?? null,
            'earliest_date'           => $earliest ?? null,
            'integrations_freshness'  => $integrationsFreshness ?? [],
            'impersonating'           => session()->has('impersonating_admin_id'),
            'impersonated_user_name'  => session()->has('impersonating_admin_id') ? $user?->name : null,
            'isLocal'                 => app()->environment('local'),
        ];
    }
}
