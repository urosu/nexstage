<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncStoreOrdersJob;
use App\Models\AdAccount;
use App\Models\DailySnapshot;
use App\Models\Order;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\User;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function overview(): Response
    {
        $now = now();

        // Workspace counts
        $workspaceTotal   = Workspace::withoutGlobalScopes()->whereNull('deleted_at')->count();
        $trialActive      = Workspace::withoutGlobalScopes()->whereNull('deleted_at')->whereNull('billing_plan')->where('trial_ends_at', '>', $now)->count();
        $trialExpired     = Workspace::withoutGlobalScopes()->whereNull('deleted_at')->whereNull('billing_plan')->where('trial_ends_at', '<=', $now)->count();
        $paying           = Workspace::withoutGlobalScopes()->whereNull('deleted_at')->whereNotNull('billing_plan')->count();
        $softDeleted      = Workspace::withoutGlobalScopes()->whereNotNull('deleted_at')->count();
        $newThisMonth     = Workspace::withoutGlobalScopes()->whereNull('deleted_at')->whereYear('created_at', $now->year)->whereMonth('created_at', $now->month)->count();

        // Plan breakdown
        $planBreakdown = Workspace::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('billing_plan')
            ->selectRaw('billing_plan, count(*) as count')
            ->groupBy('billing_plan')
            ->pluck('count', 'billing_plan');

        // Users
        $userTotal     = User::count();
        $superAdmins   = User::where('is_super_admin', true)->count();
        $newUsersMonth = User::whereYear('created_at', $now->year)->whereMonth('created_at', $now->month)->count();

        // Stores
        $storeTotal      = Store::withoutGlobalScopes()->count();
        $storeActive     = Store::withoutGlobalScopes()->where('status', 'active')->count();
        $storeError      = Store::withoutGlobalScopes()->where('status', 'error')->count();
        $storeConnecting = Store::withoutGlobalScopes()->where('status', 'connecting')->count();

        // Orders this month
        $ordersThisMonth = Order::withoutGlobalScopes()
            ->whereYear('occurred_at', $now->year)
            ->whereMonth('occurred_at', $now->month)
            ->count();

        // Failed syncs last 24 h
        $failedSyncsDay = SyncLog::withoutGlobalScopes()
            ->where('status', 'failed')
            ->where('created_at', '>=', $now->copy()->subDay())
            ->count();

        // ── SaaS revenue metrics ───────────────────────────────────────────────
        // Flat plan monthly prices (EUR). % tier and enterprise excluded from flat calc.
        $planPrices = ['starter' => 29, 'growth' => 59, 'scale' => 119];

        $flatMrr = 0;
        foreach ($planPrices as $plan => $price) {
            $flatMrr += (int) ($planBreakdown[$plan] ?? 0) * $price;
        }

        // % tier: 1% of last month's revenue per workspace, floor €149
        $percentagePlan = config('billing.percentage_plan');
        $percentageRate  = (float) $percentagePlan['rate'];
        $percentageFloor = (float) $percentagePlan['minimum_monthly'];

        $percentageWsIds = Workspace::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('billing_plan', 'percentage')
            ->pluck('id');

        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $lastMonthEnd   = $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();

        $percentageMrr = 0.0;
        if ($percentageWsIds->isNotEmpty()) {
            $lastMonthRevenue = DailySnapshot::withoutGlobalScopes()
                ->whereIn('workspace_id', $percentageWsIds)
                ->whereBetween('date', [$lastMonthStart, $lastMonthEnd])
                ->selectRaw('workspace_id, SUM(revenue) as total')
                ->groupBy('workspace_id')
                ->pluck('total', 'workspace_id');

            foreach ($percentageWsIds as $wsId) {
                $wsRevenue = (float) ($lastMonthRevenue[$wsId] ?? 0);
                $percentageMrr += max($wsRevenue * $percentageRate, $percentageFloor);
            }
        }

        $enterpriseCount = (int) ($planBreakdown['enterprise'] ?? 0);
        $mrr  = $flatMrr + $percentageMrr;
        $arr  = $mrr * 12;
        $arpa = $paying > 0 ? $mrr / $paying : 0;

        // Next-month estimate: flat stays the same; % tier uses current month revenue extrapolated
        $thisMonthStart = $now->copy()->startOfMonth()->toDateString();
        $thisMonthEnd   = $now->toDateString();
        $dayOfMonth     = (int) $now->day;
        $daysInMonth    = (int) $now->daysInMonth;

        $projectedPercentageMrr = 0.0;
        if ($percentageWsIds->isNotEmpty() && $dayOfMonth > 0) {
            $thisMonthRevenue = DailySnapshot::withoutGlobalScopes()
                ->whereIn('workspace_id', $percentageWsIds)
                ->whereBetween('date', [$thisMonthStart, $thisMonthEnd])
                ->selectRaw('workspace_id, SUM(revenue) as total')
                ->groupBy('workspace_id')
                ->pluck('total', 'workspace_id');

            foreach ($percentageWsIds as $wsId) {
                $soFar       = (float) ($thisMonthRevenue[$wsId] ?? 0);
                $extrapolated = ($soFar / $dayOfMonth) * $daysInMonth;
                $projectedPercentageMrr += max($extrapolated * $percentageRate, $percentageFloor);
            }
        } else {
            $projectedPercentageMrr = $percentageMrr;
        }

        $nextMonthEstimate = $flatMrr + $projectedPercentageMrr;

        // Recent workspace signups (last 30 days)
        $recentWorkspaces = Workspace::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $now->copy()->subDays(30))
            ->with('owner:id,name,email')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($w) => [
                'id'           => $w->id,
                'name'         => $w->name,
                'billing_plan' => $w->billing_plan,
                'trial_ends_at'=> $w->trial_ends_at?->toISOString(),
                'owner'        => $w->owner ? ['name' => $w->owner->name, 'email' => $w->owner->email] : null,
                'created_at'   => $w->created_at->toISOString(),
            ]);

        return Inertia::render('Admin/Overview', [
            'saas_revenue' => [
                'mrr'                 => round($mrr, 2),
                'arr'                 => round($arr, 2),
                'arpa'                => round($arpa, 2),
                'next_month_estimate' => round($nextMonthEstimate, 2),
                'flat_mrr'            => round($flatMrr, 2),
                'percentage_mrr'      => round($percentageMrr, 2),
                'enterprise_count'    => $enterpriseCount,
                'percentage_ws_count' => $percentageWsIds->count(),
            ],
            'stats' => [
                'workspaces' => [
                    'total'        => $workspaceTotal,
                    'paying'       => $paying,
                    'trial_active' => $trialActive,
                    'trial_expired'=> $trialExpired,
                    'soft_deleted' => $softDeleted,
                    'new_month'    => $newThisMonth,
                ],
                'users' => [
                    'total'       => $userTotal,
                    'super_admins'=> $superAdmins,
                    'new_month'   => $newUsersMonth,
                ],
                'stores' => [
                    'total'      => $storeTotal,
                    'active'     => $storeActive,
                    'error'      => $storeError,
                    'connecting' => $storeConnecting,
                ],
                'orders_this_month' => $ordersThisMonth,
                'failed_syncs_day'  => $failedSyncsDay,
                'plan_breakdown'    => $planBreakdown,
            ],
            'recent_workspaces' => $recentWorkspaces,
        ]);
    }

    public function logs(Request $request): Response
    {
        $tab    = $request->string('tab', 'sync')->toString();
        $status = $request->string('status')->toString();
        $search = $request->string('search')->toString();

        // ── Sync logs ─────────────────────────────────────────────────────────
        $syncQuery = SyncLog::withoutGlobalScopes()
            ->with('workspace:id,name,slug')
            ->orderByDesc('created_at');

        if ($status !== '') {
            $syncQuery->where('status', $status);
        }
        if ($search !== '') {
            $syncQuery->where(function ($q) use ($search): void {
                $q->where('job_type', 'ilike', "%{$search}%")
                  ->orWhere('error_message', 'ilike', "%{$search}%");
            });
        }

        $syncLogs = $syncQuery->paginate(50, ['*'], 'sync_page')->through(fn ($l) => [
            'id'                => $l->id,
            'workspace'         => $l->workspace ? ['id' => $l->workspace->id, 'name' => $l->workspace->name] : null,
            'job_type'          => $l->job_type,
            'status'            => $l->status,
            'records_processed' => $l->records_processed,
            'error_message'     => $l->error_message,
            'duration_seconds'  => $l->duration_seconds,
            'started_at'        => $l->started_at?->toISOString(),
            'created_at'        => $l->created_at->toISOString(),
        ]);

        // ── Webhook logs ──────────────────────────────────────────────────────
        $webhookQuery = WebhookLog::withoutGlobalScopes()
            ->with(['workspace:id,name', 'store:id,name'])
            ->orderByDesc('created_at');

        if ($status !== '') {
            $webhookQuery->where('status', $status);
        }
        if ($search !== '') {
            $webhookQuery->where(function ($q) use ($search): void {
                $q->where('event', 'ilike', "%{$search}%")
                  ->orWhere('error_message', 'ilike', "%{$search}%");
            });
        }

        $webhookLogs = $webhookQuery->paginate(50, ['*'], 'webhook_page')->through(fn ($l) => [
            'id'              => $l->id,
            'workspace'       => $l->workspace ? ['id' => $l->workspace->id, 'name' => $l->workspace->name] : null,
            'store'           => $l->store ? ['id' => $l->store->id, 'name' => $l->store->name] : null,
            'event'           => $l->event,
            'status'          => $l->status,
            'signature_valid' => $l->signature_valid,
            'error_message'   => $l->error_message,
            'processed_at'    => $l->processed_at?->toISOString(),
            'created_at'      => $l->created_at->toISOString(),
        ]);

        return Inertia::render('Admin/Logs', [
            'sync_logs'    => $syncLogs,
            'webhook_logs' => $webhookLogs,
            'filters'      => ['tab' => $tab, 'status' => $status, 'search' => $search],
        ]);
    }

    public function workspaces(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $query = Workspace::withoutGlobalScopes()
            ->withTrashed()
            ->withCount('stores')
            ->with('owner:id,name,email')
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('slug', 'ilike', "%{$search}%");
            });
        }

        $workspaces = $query->paginate(25)->through(fn ($w) => [
            'id'            => $w->id,
            'name'          => $w->name,
            'slug'          => $w->slug,
            'billing_plan'  => $w->billing_plan,
            'trial_ends_at' => $w->trial_ends_at?->toISOString(),
            'stores_count'  => $w->stores_count,
            'owner'         => $w->owner ? [
                'id'    => $w->owner->id,
                'name'  => $w->owner->name,
                'email' => $w->owner->email,
            ] : null,
            'created_at'    => $w->created_at->toISOString(),
            'deleted_at'    => $w->deleted_at?->toISOString(),
        ]);

        return Inertia::render('Admin/Workspaces', [
            'workspaces' => $workspaces,
            'filters'    => ['search' => $search],
        ]);
    }

    public function users(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $query = User::query()
            ->withCount('workspaces')
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $users = $query->paginate(25)->through(fn ($u) => [
            'id'              => $u->id,
            'name'            => $u->name,
            'email'           => $u->email,
            'is_super_admin'  => $u->is_super_admin,
            'workspaces_count' => $u->workspaces_count,
            'last_login_at'   => $u->last_login_at?->toISOString(),
            'created_at'      => $u->created_at->toISOString(),
        ]);

        return Inertia::render('Admin/Users', [
            'users'   => $users,
            'filters' => ['search' => $search],
        ]);
    }

    public function triggerSync(Workspace $workspace): RedirectResponse
    {
        $stores = $workspace->stores()->where('status', 'active')->get(['id', 'workspace_id']);

        foreach ($stores as $store) {
            dispatch(new SyncStoreOrdersJob($store->id, $store->workspace_id));
        }

        return back()->with('success', "Sync triggered for {$stores->count()} store(s).");
    }

    public function setPlan(Request $request, Workspace $workspace): RedirectResponse
    {
        $validated = $request->validate([
            'billing_plan' => 'required|string|in:starter,growth,scale,percentage,enterprise',
        ]);

        $workspace->update(['billing_plan' => $validated['billing_plan']]);

        return back()->with('success', 'Billing plan updated.');
    }

    public function impersonate(User $user): RedirectResponse
    {
        // Store real admin's ID so we can restore later
        session(['impersonating_admin_id' => Auth::id()]);

        Log::info('Admin impersonation', [
            'admin_id'       => Auth::id(),
            'target_user_id' => $user->id,
        ]);

        Auth::loginUsingId($user->id);

        // Set active workspace to the user's first workspace
        $firstWorkspaceId = WorkspaceUser::where('user_id', $user->id)
            ->orderBy('created_at')
            ->value('workspace_id');

        if ($firstWorkspaceId) {
            session(['active_workspace_id' => $firstWorkspaceId]);
        }

        return redirect('/dashboard');
    }

    public function stopImpersonating(): RedirectResponse
    {
        $adminId = session('impersonating_admin_id');

        if (! $adminId) {
            return redirect('/dashboard');
        }

        session()->forget(['impersonating_admin_id', 'active_workspace_id']);

        Auth::loginUsingId($adminId);

        return redirect('/admin/workspaces');
    }

    public function devSnippets(): Response
    {
        return Inertia::render('Admin/Dev/Snippets');
    }

    public function devDebug(): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $workspace   = $workspaceId ? Workspace::withoutGlobalScopes()->find($workspaceId) : null;

        $stores = $workspace
            ? Store::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->select(['id', 'name', 'slug', 'status', 'consecutive_sync_failures', 'last_synced_at', 'historical_import_status'])
                ->get()
                ->map(fn ($s) => [
                    'id'                       => $s->id,
                    'name'                     => $s->name,
                    'slug'                     => $s->slug,
                    'status'                   => $s->status,
                    'consecutive_sync_failures' => $s->consecutive_sync_failures,
                    'last_synced_at'           => $s->last_synced_at?->toISOString(),
                    'historical_import_status' => $s->historical_import_status,
                ])
            : [];

        $adAccounts = $workspace
            ? AdAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->select(['id', 'platform', 'external_id', 'name', 'currency', 'status', 'consecutive_sync_failures', 'last_synced_at'])
                ->get()
                ->map(fn ($a) => [
                    'id'                       => $a->id,
                    'platform'                 => $a->platform,
                    'external_id'              => $a->external_id,
                    'name'                     => $a->name,
                    'currency'                 => $a->currency,
                    'status'                   => $a->status,
                    'consecutive_sync_failures' => $a->consecutive_sync_failures,
                    'last_synced_at'           => $a->last_synced_at?->toISOString(),
                ])
            : [];

        $gscProperties = $workspace
            ? SearchConsoleProperty::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->select(['id', 'property_url', 'status', 'consecutive_sync_failures', 'last_synced_at'])
                ->get()
                ->map(fn ($p) => [
                    'id'                       => $p->id,
                    'property_url'             => $p->property_url,
                    'status'                   => $p->status,
                    'consecutive_sync_failures' => $p->consecutive_sync_failures,
                    'last_synced_at'           => $p->last_synced_at?->toISOString(),
                ])
            : [];

        return Inertia::render('Admin/Dev/Debug', [
            'context' => [
                'workspace_id'   => $workspaceId,
                'workspace'      => $workspace ? $workspace->only([
                    'id', 'name', 'slug', 'billing_plan', 'trial_ends_at',
                    'reporting_currency', 'reporting_timezone', 'is_orphaned', 'deleted_at', 'created_at',
                ]) : null,
                'stores'         => $stores,
                'ad_accounts'    => $adAccounts,
                'gsc_properties' => $gscProperties,
                'impersonating'  => session()->has('impersonating_admin_id'),
            ],
        ]);
    }
}
