<?php

namespace App\Http\Middleware;

use App\Models\AdAccount;
use App\Models\Alert;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
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
        $hasAdAccounts = false;
        $hasGsc = false;

        if ($user && $workspaceId) {
            $workspace = Workspace::select([
                'id', 'name', 'slug', 'reporting_currency', 'reporting_timezone',
                'trial_ends_at', 'billing_plan', 'pm_type',
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

            $stores = Store::select(['id', 'slug', 'name', 'status'])
                ->orderBy('created_at')
                ->get();

            $unreadAlertsCount = Alert::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereNull('read_at')
                ->whereNull('resolved_at')
                ->count();

            $hasAdAccounts = AdAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->exists();

            $hasGsc = SearchConsoleProperty::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->exists();

            $workspaceRole = WorkspaceUser::where('workspace_id', $workspaceId)
                ->where('user_id', $user->id)
                ->value('role');

            $earliestSnapshotDate = DB::table('daily_snapshots')
                ->where('workspace_id', $workspaceId)
                ->min('date');

            $earliestAdDate = DB::table('ad_insights')
                ->where('workspace_id', $workspaceId)
                ->whereNull('hour')
                ->min('date');

            $earliest = match (true) {
                (bool) $earliestSnapshotDate && (bool) $earliestAdDate => min($earliestSnapshotDate, $earliestAdDate),
                (bool) $earliestSnapshotDate                           => $earliestSnapshotDate,
                (bool) $earliestAdDate                                 => $earliestAdDate,
                default                                                => null,
            };
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
            ],
            'flash' => [
                'success' => session('success'),
                'error'   => session('error'),
            ],
            'workspace'              => $workspace,
            'workspaces'             => $workspaces,
            'stores'                 => $stores,
            'unread_alerts_count'    => $unreadAlertsCount,
            'has_ad_accounts'        => $hasAdAccounts,
            'has_gsc'                => $hasGsc,
            'workspace_role'         => $workspaceRole ?? null,
            'earliest_date'          => $earliest ?? null,
            'impersonating'          => session()->has('impersonating_admin_id'),
            'impersonated_user_name' => session()->has('impersonating_admin_id') ? $user?->name : null,
        ];
    }
}
