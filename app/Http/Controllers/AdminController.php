<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\BackfillAttributionDataJob;
use App\Jobs\SyncAdInsightsJob;
use App\Jobs\SyncProductsJob;
use App\Jobs\SyncRecentRefundsJob;
use App\Jobs\SyncSearchConsoleJob;
use App\Jobs\SyncStoreOrdersJob;
use App\Models\AdAccount;
use App\Models\Alert;
use App\Models\ChannelMapping;
use App\Models\DailySnapshot;
use App\Models\Order;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\User;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Attribution\AttributionParserService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function overview(): Response
    {
        $now = now();

        // Workspace counts — single aggregate query instead of 6 individual counts.
        $wsCounts = DB::selectOne("
            SELECT
                COUNT(*) FILTER (WHERE deleted_at IS NULL)                                           AS total,
                COUNT(*) FILTER (WHERE deleted_at IS NULL AND billing_plan IS NULL AND trial_ends_at > NOW()) AS trial_active,
                COUNT(*) FILTER (WHERE deleted_at IS NULL AND billing_plan IS NULL AND trial_ends_at <= NOW()) AS trial_expired,
                COUNT(*) FILTER (WHERE deleted_at IS NULL AND billing_plan IS NOT NULL)              AS paying,
                COUNT(*) FILTER (WHERE deleted_at IS NOT NULL)                                       AS soft_deleted,
                COUNT(*) FILTER (WHERE deleted_at IS NULL AND created_at >= date_trunc('month', NOW())) AS new_month
            FROM workspaces
        ");

        $workspaceTotal = (int) $wsCounts->total;
        $trialActive    = (int) $wsCounts->trial_active;
        $trialExpired   = (int) $wsCounts->trial_expired;
        $paying         = (int) $wsCounts->paying;
        $softDeleted    = (int) $wsCounts->soft_deleted;
        $newThisMonth   = (int) $wsCounts->new_month;

        // Plan breakdown
        $planBreakdown = Workspace::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('billing_plan')
            ->selectRaw('billing_plan, count(*) as count')
            ->groupBy('billing_plan')
            ->pluck('count', 'billing_plan');

        // Users — single aggregate query.
        $userCounts = DB::selectOne("
            SELECT
                COUNT(*)                                                        AS total,
                COUNT(*) FILTER (WHERE is_super_admin)                         AS super_admins,
                COUNT(*) FILTER (WHERE created_at >= date_trunc('month', NOW())) AS new_month
            FROM users
        ");

        $userTotal     = (int) $userCounts->total;
        $superAdmins   = (int) $userCounts->super_admins;
        $newUsersMonth = (int) $userCounts->new_month;

        // Stores — single aggregate query.
        $storeCounts = DB::selectOne("
            SELECT
                COUNT(*)                                       AS total,
                COUNT(*) FILTER (WHERE status = 'active')     AS active,
                COUNT(*) FILTER (WHERE status = 'error')      AS error,
                COUNT(*) FILTER (WHERE status = 'connecting') AS connecting
            FROM stores
        ");

        $storeTotal      = (int) $storeCounts->total;
        $storeActive     = (int) $storeCounts->active;
        $storeError      = (int) $storeCounts->error;
        $storeConnecting = (int) $storeCounts->connecting;

        // Orders this month
        $ordersThisMonth = Order::withoutGlobalScopes()
            ->whereBetween('occurred_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->count();

        // Failed syncs last 24 h
        $failedSyncsDay = SyncLog::withoutGlobalScopes()
            ->where('status', 'failed')
            ->where('created_at', '>=', $now->copy()->subDay())
            ->count();

        // ── SaaS revenue metrics ───────────────────────────────────────────────
        // Single plan: €39/mo min + 0.4% GMV (metered). Enterprise invoiced manually.
        $planConfig      = config('billing.plan');
        $gmvRate         = (float) ($planConfig['gmv_rate'] ?? 0.004);
        $minimumMonthly  = (float) ($planConfig['minimum_monthly'] ?? 39);

        $standardWsIds = Workspace::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('billing_plan', 'standard')
            ->select(['id'])
            ->pluck('id');

        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $lastMonthEnd   = $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();

        $mrr = 0.0;
        if ($standardWsIds->isNotEmpty()) {
            $lastMonthRevenue = DailySnapshot::withoutGlobalScopes()
                ->whereIn('workspace_id', $standardWsIds)
                ->whereBetween('date', [$lastMonthStart, $lastMonthEnd])
                ->selectRaw('workspace_id, SUM(revenue) as total')
                ->groupBy('workspace_id')
                ->pluck('total', 'workspace_id');

            foreach ($standardWsIds as $wsId) {
                $wsRevenue = (float) ($lastMonthRevenue[$wsId] ?? 0);
                $mrr += max($wsRevenue * $gmvRate, $minimumMonthly);
            }
        }

        $enterpriseCount = (int) ($planBreakdown['enterprise'] ?? 0);
        $arr  = $mrr * 12;
        $arpa = $paying > 0 ? $mrr / $paying : 0;

        // Next-month estimate: extrapolate current-month GMV to full month.
        $thisMonthStart = $now->copy()->startOfMonth()->toDateString();
        $thisMonthEnd   = $now->toDateString();
        $dayOfMonth     = (int) $now->day;
        $daysInMonth    = (int) $now->daysInMonth;

        $nextMonthEstimate = 0.0;
        if ($standardWsIds->isNotEmpty() && $dayOfMonth > 0) {
            $thisMonthRevenue = DailySnapshot::withoutGlobalScopes()
                ->whereIn('workspace_id', $standardWsIds)
                ->whereBetween('date', [$thisMonthStart, $thisMonthEnd])
                ->selectRaw('workspace_id, SUM(revenue) as total')
                ->groupBy('workspace_id')
                ->pluck('total', 'workspace_id');

            foreach ($standardWsIds as $wsId) {
                $soFar        = (float) ($thisMonthRevenue[$wsId] ?? 0);
                $extrapolated = ($soFar / $dayOfMonth) * $daysInMonth;
                $nextMonthEstimate += max($extrapolated * $gmvRate, $minimumMonthly);
            }
        } else {
            $nextMonthEstimate = $mrr;
        }

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
            'api_quotas'   => $this->getApiQuotas(),
            'saas_revenue' => [
                'mrr'                 => round($mrr, 2),
                'arr'                 => round($arr, 2),
                'arpa'                => round($arpa, 2),
                'next_month_estimate' => round($nextMonthEstimate, 2),
                'enterprise_count'    => $enterpriseCount,
                'standard_ws_count'   => $standardWsIds->count(),
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

        $syncLogs = $syncQuery->simplePaginate(50, ['*'], 'sync_page')->through(fn ($l) => [
            'id'                => $l->id,
            'workspace'         => $l->workspace ? ['id' => $l->workspace->id, 'name' => $l->workspace->name] : null,
            'job_type'          => $l->job_type,
            'status'            => $l->status,
            'records_processed' => $l->records_processed,
            'error_message'     => $l->error_message,
            'duration_seconds'  => $l->duration_seconds,
            'started_at'        => $l->started_at?->toISOString(),
            'completed_at'      => $l->completed_at?->toISOString(),
            'scheduled_at'      => $l->scheduled_at?->toISOString(),
            'queue'             => $l->queue,
            'attempt'           => $l->attempt,
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

        $webhookLogs = $webhookQuery->simplePaginate(50, ['*'], 'webhook_page')->through(fn ($l) => [
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

    public function clearLogs(Request $request): RedirectResponse
    {
        $type = $request->validate([
            'type' => 'required|in:sync,webhook',
        ])['type'];

        // TRUNCATE is orders-of-magnitude faster than DELETE on large tables
        // and avoids WAL bloat. Both tables are append-only operational logs
        // with no FK children, so truncation is safe.
        if ($type === 'sync') {
            DB::statement('TRUNCATE TABLE sync_logs RESTART IDENTITY');
        } else {
            DB::statement('TRUNCATE TABLE webhook_logs RESTART IDENTITY');
        }

        Log::info('Admin cleared logs', ['type' => $type, 'admin' => Auth::id()]);

        return back()->with('success', ucfirst($type) . ' logs cleared.');
    }

    public function queueJobs(): Response
    {
        // Currently executing jobs — tracked via sync_logs status='running'.
        // Sorted by started_at ascending so longest-running jobs appear first.
        $running = SyncLog::withoutGlobalScopes()
            ->with('workspace:id,name')
            ->where('status', 'running')
            ->orderBy('started_at')
            ->limit(100)
            ->get()
            ->map(fn ($l) => [
                'id'                => $l->id,
                'workspace'         => $l->workspace ? ['id' => $l->workspace->id, 'name' => $l->workspace->name] : null,
                'job_type'          => $l->job_type,
                'queue'             => $l->queue,
                'attempt'           => $l->attempt,
                'records_processed' => $l->records_processed,
                'started_at'        => $l->started_at?->toISOString(),
            ]);

        // Pending jobs waiting to be picked up by Horizon workers.
        // available_at is a Unix timestamp — jobs with available_at > now() are delayed.
        // Extract displayName directly in Postgres to avoid fetching and decoding full payload blobs.
        $pending = DB::table('jobs')
            ->select([
                'id', 'queue', 'attempts', 'available_at', 'created_at',
                DB::raw("payload::jsonb->>'displayName' AS display_name_fq"),
            ])
            ->orderBy('available_at')
            ->limit(200)
            ->get()
            ->map(function ($j) {
                $parts = $j->display_name_fq ? explode('\\', $j->display_name_fq) : [];
                return [
                    'id'           => $j->id,
                    'queue'        => $j->queue,
                    'display_name' => $parts ? end($parts) : '?',
                    'attempts'     => $j->attempts,
                    'available_at' => Carbon::createFromTimestamp($j->available_at)->toISOString(),
                    'created_at'   => Carbon::createFromTimestamp($j->created_at)->toISOString(),
                ];
            });

        // Jobs that have exhausted all retries and landed in failed_jobs.
        $failedQueue = DB::table('failed_jobs')
            ->select([
                'id', 'uuid', 'queue', 'failed_at', 'exception',
                DB::raw("payload::jsonb->>'displayName' AS display_name_fq"),
            ])
            ->orderByDesc('failed_at')
            ->limit(100)
            ->get()
            ->map(function ($j) {
                $parts = $j->display_name_fq ? explode('\\', $j->display_name_fq) : [];
                return [
                    'id'           => $j->id,
                    'uuid'         => $j->uuid,
                    'queue'        => $j->queue,
                    'display_name' => $parts ? end($parts) : '?',
                    'exception'    => mb_substr($j->exception, 0, 1000),
                    'failed_at'    => $j->failed_at,
                ];
            });

        return Inertia::render('Admin/Queue', [
            'running'      => $running,
            'pending'      => $pending,
            'failed_queue' => $failedQueue,
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

        $page = $query->paginate(25);

        // Pre-fetch all backfill cache keys for this page in a single Redis MGET.
        $pageIds      = $page->getCollection()->pluck('id');
        $cacheKeys    = $pageIds->map(fn (int $id) => BackfillAttributionDataJob::cacheKey($id))->all();
        $backfillData = Cache::many($cacheKeys);

        $workspaces = $page->through(function ($w) use ($backfillData) {
            $backfill = $backfillData[BackfillAttributionDataJob::cacheKey($w->id)] ?? null;

            return [
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
                // Backfill progress from Cache (null when never dispatched).
                'attribution_backfill' => $backfill,
            ];
        });

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

    /**
     * Dispatch BackfillAttributionDataJob for a single workspace.
     *
     * Idempotent — safe to dispatch multiple times. Each run re-parses all orders
     * and overwrites attribution_* columns. Typically run once per workspace during
     * beta onboarding after AttributionParserService is deployed.
     *
     * Progress is stored in Cache and surfaced on /admin/system-health (Step 15).
     */
    public function dispatchAttributionBackfill(Workspace $workspace): RedirectResponse
    {
        dispatch(new BackfillAttributionDataJob($workspace->id));

        Log::info('Admin dispatched attribution backfill', [
            'workspace_id' => $workspace->id,
            'admin_id'     => Auth::id(),
        ]);

        return back()->with('success', "Attribution backfill queued for {$workspace->name}.");
    }

    public function triggerSync(Workspace $workspace): RedirectResponse
    {
        $stores = $workspace->stores()->where('status', 'active')->get(['id', 'workspace_id']);

        foreach ($stores as $store) {
            dispatch(new SyncStoreOrdersJob($store->id, $store->workspace_id));
            dispatch(new SyncRecentRefundsJob($store->id, $store->workspace_id));
            dispatch(new SyncProductsJob($store->id, $store->workspace_id));
        }

        $adAccounts = $workspace->adAccounts()->where('status', 'active')->get(['id', 'workspace_id', 'platform']);

        foreach ($adAccounts as $account) {
            dispatch(new SyncAdInsightsJob($account->id, $account->workspace_id, $account->platform));
        }

        $gscProperties = $workspace->searchConsoleProperties()->where('status', 'active')->get(['id', 'workspace_id']);

        foreach ($gscProperties as $property) {
            dispatch(new SyncSearchConsoleJob($property->id, $property->workspace_id));
        }

        return back()->with('success', "Full sync triggered: {$stores->count()} store(s), {$adAccounts->count()} ad account(s), {$gscProperties->count()} GSC property(s).");
    }

    public function setPlan(Request $request, Workspace $workspace): RedirectResponse
    {
        $validated = $request->validate([
            'billing_plan' => 'required|string|in:standard,enterprise',
        ]);

        $workspace->update(['billing_plan' => $validated['billing_plan']]);

        return back()->with('success', 'Billing plan updated.');
    }

    public function impersonate(Request $request, User $user): RedirectResponse
    {
        // Store real admin's ID so we can restore later
        session(['impersonating_admin_id' => Auth::id()]);

        Log::info('Admin impersonation', [
            'admin_id'       => Auth::id(),
            'target_user_id' => $user->id,
        ]);

        Auth::loginUsingId($user->id);

        // Regenerate the session ID after switching identity to prevent session fixation.
        $request->session()->regenerate();

        // Set active workspace to the user's first workspace
        $firstWorkspaceId = WorkspaceUser::where('user_id', $user->id)
            ->orderBy('created_at')
            ->value('workspace_id');

        if ($firstWorkspaceId) {
            session(['active_workspace_id' => $firstWorkspaceId]);
        }

        return redirect('/onboarding');
    }

    public function stopImpersonating(Request $request): RedirectResponse
    {
        $adminId = session('impersonating_admin_id');

        if (! $adminId) {
            return redirect('/onboarding');
        }

        // Verify the stored ID actually belongs to a super admin before restoring.
        // Prevents session manipulation from elevating an arbitrary user to admin.
        $admin = User::find($adminId);

        if (! $admin || ! $admin->is_super_admin) {
            session()->forget(['impersonating_admin_id', 'active_workspace_id']);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        session()->forget(['impersonating_admin_id', 'active_workspace_id']);

        Auth::loginUsingId($adminId);

        // Regenerate the session ID after switching identity to prevent session fixation.
        $request->session()->regenerate();

        return redirect('/admin/workspaces');
    }

    // ── API quota telemetry ────────────────────────────────────────────────────

    /**
     * Read API quota snapshots from cache (written by platform API clients).
     *
     * Facebook: FacebookAdsClient writes to these keys on every API call.
     *   facebook_api_usage              — last observed usage % + tier metadata
     *   facebook_api_throttled_until    — ISO timestamp; present only while throttled
     *   facebook_api_last_throttle_at   — ISO timestamp of last throttle event
     *   facebook_api_rate_limit_hits_YYYY-MM-DD — daily hit counter (2-day TTL)
     *
     * Returns null values for each field when no API calls have been made yet
     * (e.g. fresh install with no connected ad accounts).
     *
     * @return array<string, mixed>
     */
    /**
     * Read API quota snapshots from cache (written by platform API clients).
     *
     * Facebook: FacebookAdsClient writes to these keys on every API call.
     *   facebook_api_usage              — last observed usage % + tier metadata (30-min TTL)
     *   facebook_api_throttled_until    — ISO timestamp; present only while throttled
     *   facebook_api_last_throttle_at   — ISO timestamp of last throttle event (24h TTL)
     *   facebook_api_rate_limit_hits_YYYY-MM-DD — daily hit counter (2-day TTL)
     *
     * Google Ads / GSC: no usage headers exposed by Google — only reactive throttle events.
     *   google_ads_throttled_until / gsc_throttled_until
     *   google_ads_last_throttle_at / gsc_last_throttle_at
     *   google_ads_rate_limit_hits_YYYY-MM-DD / gsc_rate_limit_hits_YYYY-MM-DD
     *
     * @return array<string, mixed>
     */
    private function getApiQuotas(): array
    {
        $today = now()->toDateString();

        $fbUsage          = Cache::get('facebook_api_usage');
        $fbThrottledUntil = Cache::get('facebook_api_throttled_until');
        $fbLastThrottleAt = Cache::get('facebook_api_last_throttle_at');
        $fbHitsToday      = (int) Cache::get('facebook_api_rate_limit_hits_' . $today, 0);
        $fbCallsToday     = (int) Cache::get('facebook_api_calls_' . $today, 0);
        $fbLastSuccessAt  = Cache::get('facebook_api_last_success_at');

        $gadsThrottledUntil = Cache::get('google_ads_throttled_until');
        $gadsLastThrottleAt = Cache::get('google_ads_last_throttle_at');
        $gadsHitsToday      = (int) Cache::get('google_ads_rate_limit_hits_' . $today, 0);
        $gadsCallsToday     = (int) Cache::get('google_ads_calls_' . $today, 0);
        $gadsLastSuccessAt  = Cache::get('google_ads_last_success_at');

        $gscThrottledUntil = Cache::get('gsc_throttled_until');
        $gscLastThrottleAt = Cache::get('gsc_last_throttle_at');
        $gscHitsToday      = (int) Cache::get('gsc_rate_limit_hits_' . $today, 0);
        $gscCallsToday     = (int) Cache::get('gsc_calls_' . $today, 0);
        $gscLastSuccessAt  = Cache::get('gsc_last_success_at');

        $psiThrottledUntil = Cache::get('psi_throttled_until');
        $psiLastThrottleAt = Cache::get('psi_last_throttle_at');
        $psiHitsToday      = (int) Cache::get('psi_rate_limit_hits_' . $today, 0);
        $psiCallsToday     = (int) Cache::get('psi_calls_' . $today, 0);
        $psiLastSuccessAt  = Cache::get('psi_last_success_at');

        return [
            'facebook' => [
                'usage_pct'        => $fbUsage ? (int) $fbUsage['pct'] : null,
                'tier'             => $fbUsage ? (string) $fbUsage['tier'] : null,
                'threshold_pct'    => $fbUsage ? (int) $fbUsage['threshold'] : null,
                'hard_cap_pct'     => $fbUsage ? ($fbUsage['hard_cap'] ?? null) : null,
                'observed_at'      => $fbUsage ? (string) $fbUsage['observed_at'] : null,
                'throttled_until'  => $fbThrottledUntil ?? null,
                'last_throttle_at' => $fbLastThrottleAt ?? null,
                'hits_today'       => $fbHitsToday,
                'calls_today'      => $fbCallsToday,
                'last_success_at'  => $fbLastSuccessAt ?? null,
            ],
            'google_ads' => [
                'throttled_until'  => $gadsThrottledUntil ?? null,
                'last_throttle_at' => $gadsLastThrottleAt ?? null,
                'hits_today'       => $gadsHitsToday,
                'calls_today'      => $gadsCallsToday,
                'last_success_at'  => $gadsLastSuccessAt ?? null,
            ],
            'gsc' => [
                'throttled_until'  => $gscThrottledUntil ?? null,
                'last_throttle_at' => $gscLastThrottleAt ?? null,
                'hits_today'       => $gscHitsToday,
                'calls_today'      => $gscCallsToday,
                'last_success_at'  => $gscLastSuccessAt ?? null,
            ],
            'psi' => [
                'throttled_until'  => $psiThrottledUntil ?? null,
                'last_throttle_at' => $psiLastThrottleAt ?? null,
                'hits_today'       => $psiHitsToday,
                'calls_today'      => $psiCallsToday,
                'last_success_at'  => $psiLastSuccessAt ?? null,
            ],
        ];
    }

    /**
     * Attribution debug page — shows the full parser pipeline for a single order.
     *
     * Every source is run (via AttributionParserService::debug()) and the trace
     * shows which source matched, what it extracted, and why earlier sources were
     * skipped. Essential for beta debugging of PYS vs WC-native misattribution.
     *
     * Accessible only to super admins (/admin prefix route group).
     * Order is fetched without WorkspaceScope so any order can be inspected.
     *
     * @see PLANNING.md section 6 (Debug route)
     */
    public function attributionDebug(int $orderId, AttributionParserService $parser): Response
    {
        $order = Order::withoutGlobalScopes()->with(['store', 'workspace'])->findOrFail($orderId);

        $pipeline = $parser->debug($order);

        // Serialise ParsedAttribution objects for the Inertia payload.
        $pipelineData = array_map(static function (array $step): array {
            $result = $step['result'];

            return [
                'source'  => $step['source'],
                'matched' => $step['matched'],
                'skipped' => $step['skipped'] ?? false,
                'result'  => $result === null ? null : [
                    'source_type'  => $result->source_type,
                    'first_touch'  => $result->first_touch,
                    'last_touch'   => $result->last_touch,
                    'click_ids'    => $result->click_ids,
                    'channel'      => $result->channel,
                    'channel_type' => $result->channel_type,
                    'raw_data'     => $result->raw_data,
                ],
            ];
        }, $pipeline);

        return Inertia::render('Admin/AttributionDebug', [
            'order' => [
                'id'               => $order->id,
                'external_id'      => $order->external_id,
                'occurred_at'      => $order->occurred_at?->toISOString(),
                'workspace_id'     => $order->workspace_id,
                'store_name'       => $order->store?->name,
                'utm_source'       => $order->utm_source,
                'utm_medium'       => $order->utm_medium,
                'utm_campaign'     => $order->utm_campaign,
                'source_type'      => $order->source_type,
                'attribution_source' => $order->attribution_source,
                'raw_meta_keys'    => is_array($order->raw_meta) ? array_keys($order->raw_meta) : [],
            ],
            'pipeline' => $pipelineData,
        ]);
    }

    /**
     * System health dashboard — per-queue depth, wait time, sync freshness per store,
     * NULL FX rate counts, and attribution backfill progress per workspace.
     *
     * All data is read-only. Refreshed on every page load (no caching here — this page
     * is already admin-only and low-traffic).
     *
     * @see PLANNING.md section 22.5 (Observability)
     */
    public function systemHealth(): Response
    {
        // ── Queue depth per named queue ────────────────────────────────────────
        // Read directly from the `jobs` table; Horizon API requires Redis access.
        // `available_at` <= now() = immediately ready; > now() = delayed/scheduled.
        $knownQueues = [
            'critical-webhooks',
            'sync-facebook',
            'sync-google-ads',
            'sync-google-search',
            'sync-store',
            'sync-psi',
            'imports-store',
            'imports-ads',
            'imports-gsc',
            'default',
            'low',
        ];

        $depthRows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) AS depth, MIN(available_at) AS oldest_available_at')
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $failedRows = DB::table('failed_jobs')
            ->selectRaw('queue, COUNT(*) AS failed_count')
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $now = now()->timestamp;
        $queues = array_map(function (string $queue) use ($depthRows, $failedRows, $now): array {
            $row    = $depthRows->get($queue);
            $failed = $failedRows->get($queue);
            $depth  = $row ? (int) $row->depth : 0;
            // Wait time: seconds since the oldest ready job was pushed.
            $waitSeconds = ($row && $row->oldest_available_at)
                ? max(0, $now - (int) $row->oldest_available_at)
                : 0;

            return [
                'queue'        => $queue,
                'depth'        => $depth,
                'wait_seconds' => $waitSeconds,
                'failed_count' => $failed ? (int) $failed->failed_count : 0,
            ];
        }, $knownQueues);

        // ── Sync freshness per store ────────────────────────────────────────────
        // A store is considered stale when its last_synced_at is > 2 hours ago (for active stores)
        // OR when consecutive_sync_failures > 0. Webhook health is derived from store_webhooks.
        $stores = Store::withoutGlobalScopes()
            ->with('workspace:id,name,slug')
            ->select([
                'id', 'workspace_id', 'name', 'status',
                'last_synced_at', 'consecutive_sync_failures',
                'historical_import_status',
            ])
            ->orderBy('workspace_id')
            ->orderBy('name')
            ->get()
            ->map(function ($s): array {
                $staleThreshold = now()->subHours(2);
                $isStale = $s->status === 'active'
                    && ($s->last_synced_at === null || $s->last_synced_at->lt($staleThreshold));

                return [
                    'id'                        => $s->id,
                    'workspace'                 => $s->workspace
                        ? ['id' => $s->workspace->id, 'name' => $s->workspace->name]
                        : null,
                    'name'                      => $s->name,
                    'status'                    => $s->status,
                    'last_synced_at'            => $s->last_synced_at?->toISOString(),
                    'consecutive_sync_failures' => $s->consecutive_sync_failures ?? 0,
                    'historical_import_status'  => $s->historical_import_status,
                    'is_stale'                  => $isStale,
                ];
            });

        // ── NULL FX rate counts ────────────────────────────────────────────────
        // Orders with total_in_reporting_currency = NULL failed FX conversion.
        // RetryMissingConversionJob handles these nightly. High counts indicate FX feed issues.
        $nullFxTotal = Order::withoutGlobalScopes()
            ->whereNull('total_in_reporting_currency')
            ->whereIn('status', ['completed', 'processing'])
            ->count();

        $nullFxByWorkspace = Order::withoutGlobalScopes()
            ->whereNull('total_in_reporting_currency')
            ->whereIn('status', ['completed', 'processing'])
            ->selectRaw('workspace_id, COUNT(*) AS null_count')
            ->groupBy('workspace_id')
            ->orderByDesc('null_count')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'workspace_id' => $r->workspace_id,
                'null_count'   => (int) $r->null_count,
            ]);

        // ── Attribution backfill progress per workspace ─────────────────────────
        // Fetch all progress keys in a single Redis MGET instead of N individual GETs.
        $workspaceIds = Workspace::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->pluck('id', 'name');

        $cacheKeys = $workspaceIds->map(
            fn (int $id) => BackfillAttributionDataJob::cacheKey($id)
        )->values()->all();

        $progressValues = Cache::many($cacheKeys);

        $backfillProgress = [];
        foreach ($workspaceIds as $name => $id) {
            $key = BackfillAttributionDataJob::cacheKey($id);
            $backfillProgress[] = [
                'workspace_id'   => $id,
                'workspace_name' => $name,
                'progress'       => $progressValues[$key] ?? null,
            ];
        }

        // ── Currently running jobs (from sync_logs) ────────────────────────────
        $runningCount = SyncLog::withoutGlobalScopes()
            ->where('status', 'running')
            ->count();

        return Inertia::render('Admin/SystemHealth', [
            'queues'             => $queues,
            'stores'             => $stores,
            'null_fx_total'      => $nullFxTotal,
            'null_fx_breakdown'  => $nullFxByWorkspace,
            'backfill_progress'  => $backfillProgress,
            'running_jobs'       => $runningCount,
            'api_quotas'         => $this->getApiQuotas(),
        ]);
    }

    /**
     * Silent alerts review UI — shows all alerts where is_silent = true that have not yet
     * been reviewed. Founder reviews each alert as TP (true positive), FP (false positive),
     * or unclear. Used to track the graduation threshold (≥70% TP rate on ≥20 reviewed alerts
     * over ≥4 weeks before silent mode is turned off).
     *
     * @see PLANNING.md section 17 (Silent mode graduation)
     */
    public function silentAlerts(Request $request): Response
    {
        $tab = $request->string('tab', 'unreviewed')->toString();

        $baseQuery = Alert::withoutGlobalScopes()
            ->where('is_silent', true)
            ->with(['workspace:id,name,slug', 'store:id,name'])
            ->orderByDesc('created_at');

        $query = match ($tab) {
            'unreviewed' => (clone $baseQuery)->whereNull('review_status'),
            'tp'         => (clone $baseQuery)->where('review_status', 'tp'),
            'fp'         => (clone $baseQuery)->where('review_status', 'fp'),
            'unclear'    => (clone $baseQuery)->where('review_status', 'unclear'),
            default      => (clone $baseQuery)->whereNull('review_status'),
        };

        $alerts = $query->paginate(50)->through(fn ($a) => [
            'id'                => $a->id,
            'workspace'         => $a->workspace ? ['id' => $a->workspace->id, 'name' => $a->workspace->name] : null,
            'store'             => $a->store ? ['id' => $a->store->id, 'name' => $a->store->name] : null,
            'type'              => $a->type,
            'severity'          => $a->severity,
            'source'            => $a->source,
            'data'              => $a->data,
            'review_status'     => $a->review_status,
            'reviewed_at'       => $a->reviewed_at?->toISOString(),
            'estimated_impact_low'  => $a->estimated_impact_low,
            'estimated_impact_high' => $a->estimated_impact_high,
            'created_at'        => $a->created_at->toISOString(),
        ]);

        // Counts for tab badges
        $counts = Alert::withoutGlobalScopes()
            ->where('is_silent', true)
            ->selectRaw("
                COUNT(*) FILTER (WHERE review_status IS NULL)   AS unreviewed,
                COUNT(*) FILTER (WHERE review_status = 'tp')    AS tp,
                COUNT(*) FILTER (WHERE review_status = 'fp')    AS fp,
                COUNT(*) FILTER (WHERE review_status = 'unclear') AS unclear
            ")
            ->first();

        // TP rate for graduation tracking (requires ≥20 reviewed alerts)
        $totalReviewed = (int) (($counts->tp ?? 0) + ($counts->fp ?? 0) + ($counts->unclear ?? 0));
        $tpRate        = $totalReviewed > 0
            ? round((int) ($counts->tp ?? 0) / $totalReviewed * 100, 1)
            : null;

        return Inertia::render('Admin/SilentAlerts', [
            'alerts'         => $alerts,
            'tab'            => $tab,
            'counts'         => [
                'unreviewed' => (int) ($counts->unreviewed ?? 0),
                'tp'         => (int) ($counts->tp         ?? 0),
                'fp'         => (int) ($counts->fp         ?? 0),
                'unclear'    => (int) ($counts->unclear    ?? 0),
            ],
            'graduation'     => [
                'total_reviewed' => $totalReviewed,
                'tp_rate'        => $tpRate,
                'threshold_met'  => $tpRate !== null && $totalReviewed >= 20 && $tpRate >= 70.0,
            ],
        ]);
    }

    /**
     * Mark a single silent alert with a review status (tp / fp / unclear).
     *
     * Idempotent — re-submitting the same status updates reviewed_at again.
     */
    public function reviewAlert(Alert $alert, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'review_status' => 'required|in:tp,fp,unclear',
        ]);

        $alert->withoutGlobalScopes()->forceFill([
            'review_status' => $validated['review_status'],
            'reviewed_at'   => now(),
        ])->save();

        Log::info('Admin reviewed silent alert', [
            'alert_id'      => $alert->id,
            'review_status' => $validated['review_status'],
            'admin_id'      => Auth::id(),
        ]);

        return back()->with('success', 'Alert reviewed.');
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

    // ── Channel Mappings ──────────────────────────────────────────────────

    /**
     * List global channel mappings and surface unrecognized UTM sources.
     */
    public function channelMappings(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $query = ChannelMapping::whereNull('workspace_id')
            ->orderBy('channel_type')
            ->orderBy('utm_source_pattern')
            ->orderBy('utm_medium_pattern');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('utm_source_pattern', 'ilike', "%{$search}%")
                  ->orWhere('channel_name', 'ilike', "%{$search}%")
                  ->orWhere('channel_type', 'ilike', "%{$search}%");
            });
        }

        $mappings = $query->paginate(50)->through(fn (ChannelMapping $m) => [
            'id'                  => $m->id,
            'utm_source_pattern'  => $m->utm_source_pattern,
            'utm_medium_pattern'  => $m->utm_medium_pattern,
            'channel_name'        => $m->channel_name,
            'channel_type'        => $m->channel_type,
            'is_global'           => $m->is_global,
            'created_at'          => $m->created_at->toISOString(),
        ]);

        // Unrecognized sources across all workspaces (last 90 days, top 20)
        $unrecognized = DB::select(<<<'SQL'
            SELECT
                LOWER(attribution_last_touch->>'source')  AS source,
                LOWER(attribution_last_touch->>'medium')  AS medium,
                COUNT(*)                                   AS order_count,
                COUNT(DISTINCT workspace_id)               AS workspace_count
            FROM orders
            WHERE status IN ('completed', 'processing')
              AND attribution_source IN ('pys', 'wc_native')
              AND attribution_last_touch IS NOT NULL
              AND attribution_last_touch->>'source' IS NOT NULL
              AND (attribution_last_touch->>'channel' IS NULL OR attribution_last_touch->>'channel' = '')
              AND occurred_at >= NOW() - INTERVAL '90 days'
            GROUP BY 1, 2
            ORDER BY order_count DESC
            LIMIT 20
        SQL);

        return Inertia::render('Admin/ChannelMappings', [
            'mappings'     => $mappings,
            'unrecognized' => $unrecognized,
            'filters'      => ['search' => $search],
        ]);
    }

    /**
     * Create a new global channel mapping.
     */
    public function storeChannelMapping(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'utm_source_pattern' => ['required', 'string', 'max:255'],
            'utm_medium_pattern' => ['nullable', 'string', 'max:255'],
            'channel_name'       => ['required', 'string', 'max:120'],
            'channel_type'       => ['required', 'string', 'in:email,paid_social,paid_search,organic_search,organic_social,direct,referral,affiliate,sms,other'],
        ]);

        $source = strtolower(trim($validated['utm_source_pattern']));
        $medium = isset($validated['utm_medium_pattern']) && $validated['utm_medium_pattern'] !== ''
            ? strtolower(trim($validated['utm_medium_pattern']))
            : null;

        try {
            ChannelMapping::create([
                'workspace_id'       => null,
                'utm_source_pattern' => $source,
                'utm_medium_pattern' => $medium,
                'channel_name'       => $validated['channel_name'],
                'channel_type'       => $validated['channel_type'],
                'is_global'          => true,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), '23505')) {
                return back()->withErrors([
                    'utm_source_pattern' => 'A global mapping for this source/medium combination already exists.',
                ]);
            }
            throw $e;
        }

        return back()->with('success', "Mapping created: {$source} → {$validated['channel_name']}");
    }

    /**
     * Update an existing global channel mapping.
     */
    public function updateChannelMapping(Request $request, ChannelMapping $channelMapping): RedirectResponse
    {
        abort_unless($channelMapping->workspace_id === null, 404);

        $validated = $request->validate([
            'utm_source_pattern' => ['required', 'string', 'max:255'],
            'utm_medium_pattern' => ['nullable', 'string', 'max:255'],
            'channel_name'       => ['required', 'string', 'max:120'],
            'channel_type'       => ['required', 'string', 'in:email,paid_social,paid_search,organic_search,organic_social,direct,referral,affiliate,sms,other'],
        ]);

        $source = strtolower(trim($validated['utm_source_pattern']));
        $medium = isset($validated['utm_medium_pattern']) && $validated['utm_medium_pattern'] !== ''
            ? strtolower(trim($validated['utm_medium_pattern']))
            : null;

        try {
            $channelMapping->update([
                'utm_source_pattern' => $source,
                'utm_medium_pattern' => $medium,
                'channel_name'       => $validated['channel_name'],
                'channel_type'       => $validated['channel_type'],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), '23505')) {
                return back()->withErrors([
                    'utm_source_pattern' => 'A global mapping for this source/medium combination already exists.',
                ]);
            }
            throw $e;
        }

        return back()->with('success', "Mapping updated: {$source} → {$validated['channel_name']}");
    }

    /**
     * Delete a global channel mapping.
     */
    public function destroyChannelMapping(ChannelMapping $channelMapping): RedirectResponse
    {
        abort_unless($channelMapping->workspace_id === null, 404);

        $name = $channelMapping->channel_name;
        $channelMapping->delete();

        return back()->with('success', "Mapping deleted: {$name}");
    }
}
