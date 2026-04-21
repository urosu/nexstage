<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteWorkspaceAction;
use App\Actions\UpdateWorkspaceAction;
use App\Models\AdAccount;
use App\Models\Alert;
use App\Models\DailyNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\Workspace;
use App\Models\WorkspaceEvent;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('viewSettings', $workspace);

        return Inertia::render('Settings/Workspace', [
            'workspace' => [
                'id'                   => $workspace->id,
                'name'                 => $workspace->name,
                'slug'                 => $workspace->slug,
                'reporting_currency'   => $workspace->reporting_currency,
                'reporting_timezone'   => $workspace->reporting_timezone,
                'billing_plan'         => $workspace->billing_plan,
                'trial_ends_at'        => $workspace->trial_ends_at,
                'target_roas'          => $workspace->target_roas ? (float) $workspace->target_roas : null,
                'target_cpo'           => $workspace->target_cpo  ? (float) $workspace->target_cpo  : null,
                'has_store'            => $workspace->has_store,
                'has_ads'              => $workspace->has_ads,
                'holiday_lead_days'              => $workspace->workspace_settings->holidayLeadDays,
                'holiday_notification_days'      => $workspace->workspace_settings->holidayNotificationDays,
                'commercial_notification_days'   => $workspace->workspace_settings->commercialNotificationDays,
            ],
            'userRole' => $this->resolveUserRole($request, $workspace),
        ]);
    }

    public function update(Request $request, UpdateWorkspaceAction $action): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('update', $workspace);

        $isOwner = $workspace->workspaceUsers()
            ->where('user_id', $request->user()->id)
            ->where('role', 'owner')
            ->exists();

        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            // Slug may only be changed by the owner. The regex mirrors store slug rules.
            'slug'                => $isOwner
                ? ['required', 'string', 'min:4', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', Rule::unique('workspaces', 'slug')->ignore($workspace->id)]
                : ['prohibited'],
            'reporting_currency'  => ['required', 'string', 'size:3'],
            'reporting_timezone'  => ['required', 'string', 'max:100', Rule::in(\DateTimeZone::listIdentifiers())],
            // Performance targets — used for Winners/Losers filter chips. Null = use break-even defaults (1.0× ROAS).
            'target_roas'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'target_cpo'          => ['nullable', 'numeric', 'min:0'],
            'holiday_lead_days'              => ['nullable', 'integer', 'min:0', 'max:90'],
            'holiday_notification_days'      => ['nullable', 'integer', 'min:0', 'max:90'],
            'commercial_notification_days'   => ['nullable', 'integer', 'min:0', 'max:90'],
        ]);

        // Holiday/commercial fields live inside the workspace_settings JSONB, not direct columns.
        $settings                              = $workspace->workspace_settings;
        $settings->holidayLeadDays             = (int) ($validated['holiday_lead_days'] ?? 0);
        $settings->holidayNotificationDays     = (int) ($validated['holiday_notification_days'] ?? 0);
        $settings->commercialNotificationDays  = (int) ($validated['commercial_notification_days'] ?? 0);
        unset($validated['holiday_lead_days'], $validated['holiday_notification_days'], $validated['commercial_notification_days']);
        $validated['workspace_settings'] = $settings;

        $slugChanged = $isOwner
            && isset($validated['slug'])
            && $validated['slug'] !== $workspace->slug;

        $action->handle($workspace, $validated);

        if ($slugChanged) {
            // Redirect to the settings page under the new slug so the URL stays valid.
            return redirect("/{$validated['slug']}/settings/workspace")
                ->with('success', 'Workspace settings updated.');
        }

        return back()->with('success', 'Workspace settings updated.');
    }

    public function destroy(Request $request, DeleteWorkspaceAction $action): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('delete', $workspace);

        $request->validate([
            'confirmation' => ['required', 'string', Rule::in([$workspace->name])],
        ]);

        try {
            $action->handle($workspace);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['deletion' => $e->getMessage()]);
        }

        $request->session()->forget('active_workspace_id');

        return redirect('/onboarding')
            ->with('success', 'Workspace deleted. You have 30 days to restore it.');
    }

    /**
     * GDPR data export — streams a JSON bundle of all workspace data.
     *
     * Produces a complete, machine-readable bundle of every data category Nexstage
     * holds for the workspace. Designed to satisfy GDPR Art. 20 (data portability)
     * and Art. 15 (right of access).
     *
     * Restricted to workspace owners (most-privileged role).
     *
     * Bundle structure:
     *   workspace      — core workspace row (no billing secrets)
     *   members        — user accounts with roles
     *   stores         — connected store metadata
     *   orders         — all orders (attribution + financials; excludes raw_meta)
     *   order_items    — line items for all orders
     *   ad_accounts    — connected ad account metadata
     *   events         — workspace events (coupon spikes, manual annotations)
     *   daily_notes    — text notes written by users
     *   alerts         — anomaly alerts (silent and delivered)
     *
     * Billing information (Stripe IDs, card details) and raw API responses
     * (raw_meta, raw_insights) are intentionally excluded — they are either
     * covered by Stripe's own DPA or contain no user PII.
     *
     * @see PLANNING.md section 25 Phase 1.5 Step 15 (operational prerequisites)
     */
    public function gdprExport(Request $request): JsonResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        // Only the workspace owner may download the full data bundle.
        $isOwner = WorkspaceUser::where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->where('role', 'owner')
            ->exists();

        abort_unless($isOwner, 403, 'Only the workspace owner can export workspace data.');

        $bundle = [
            'exported_at' => now()->toISOString(),
            'workspace'   => [
                'id'                   => $workspace->id,
                'name'                 => $workspace->name,
                'slug'                 => $workspace->slug,
                'reporting_currency'   => $workspace->reporting_currency,
                'reporting_timezone'   => $workspace->reporting_timezone,
                'primary_country_code' => $workspace->primary_country_code ?? null,
                'created_at'           => $workspace->created_at->toISOString(),
            ],
            'members' => WorkspaceUser::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->with('user:id,name,email,created_at,last_login_at')
                ->get()
                ->map(fn ($wu) => [
                    'role'          => $wu->role,
                    'joined_at'     => $wu->created_at->toISOString(),
                    'user_id'       => $wu->user_id,
                    'user_name'     => $wu->user->name,
                    'user_email'    => $wu->user->email,
                    'last_login_at' => $wu->user->last_login_at?->toISOString(),
                ]),
            'stores' => Store::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->get(['id', 'name', 'slug', 'website_url', 'platform', 'status', 'primary_country_code', 'created_at'])
                ->map(fn ($s) => [
                    'id'                   => $s->id,
                    'name'                 => $s->name,
                    'slug'                 => $s->slug,
                    'website_url'          => $s->website_url,
                    'platform'             => $s->platform,
                    'status'               => $s->status,
                    'primary_country_code' => $s->primary_country_code,
                    'created_at'           => $s->created_at->toISOString(),
                ]),
            // Orders: exclude raw_meta (vendor API response, not user PII) per above note.
            'orders' => Order::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->select([
                    'id', 'store_id', 'external_id', 'order_number', 'status',
                    'currency', 'subtotal', 'total', 'total_in_reporting_currency',
                    'attribution_source', 'attribution_last_touch',
                    'utm_source', 'utm_medium', 'utm_campaign',
                    'occurred_at', 'created_at',
                ])
                ->orderBy('occurred_at')
                ->get()
                ->map(fn ($o) => [
                    'id'                          => $o->id,
                    'store_id'                    => $o->store_id,
                    'external_id'                 => $o->external_id,
                    'order_number'                => $o->order_number,
                    'status'                      => $o->status,
                    'currency'                    => $o->currency,
                    'subtotal'                    => $o->subtotal,
                    'total'                       => $o->total,
                    'total_in_reporting_currency' => $o->total_in_reporting_currency,
                    'attribution_source'          => $o->attribution_source,
                    'attribution_last_touch'      => $o->attribution_last_touch,
                    'utm_source'                  => $o->utm_source,
                    'utm_medium'                  => $o->utm_medium,
                    'utm_campaign'                => $o->utm_campaign,
                    'occurred_at'                 => $o->occurred_at?->toISOString(),
                    'created_at'                  => $o->created_at->toISOString(),
                ]),
            'order_items' => DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.workspace_id', $workspace->id)
                ->select([
                    'order_items.id', 'order_items.order_id', 'order_items.external_id',
                    'order_items.name', 'order_items.quantity', 'order_items.unit_price',
                    'order_items.unit_cost', 'order_items.total',
                ])
                ->orderBy('order_items.order_id')
                ->get()
                ->map(fn ($r) => (array) $r),
            'ad_accounts' => AdAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->select(['id', 'platform', 'external_id', 'name', 'currency', 'status', 'created_at'])
                ->get()
                ->map(fn ($a) => [
                    'id'          => $a->id,
                    'platform'    => $a->platform,
                    'external_id' => $a->external_id,
                    'name'        => $a->name,
                    'currency'    => $a->currency,
                    'status'      => $a->status,
                    'created_at'  => $a->created_at->toISOString(),
                ]),
            'events' => WorkspaceEvent::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->orderBy('event_date')
                ->get(['id', 'event_date', 'event_type', 'label', 'is_auto_detected', 'created_at'])
                ->map(fn ($e) => [
                    'id'               => $e->id,
                    'event_date'       => $e->event_date,
                    'event_type'       => $e->event_type,
                    'label'            => $e->label,
                    'is_auto_detected' => $e->is_auto_detected,
                    'created_at'       => $e->created_at->toISOString(),
                ]),
            'daily_notes' => DailyNote::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->orderBy('note_date')
                ->get(['id', 'note_date', 'content', 'created_at'])
                ->map(fn ($n) => [
                    'id'         => $n->id,
                    'note_date'  => $n->note_date,
                    'content'    => $n->content,
                    'created_at' => $n->created_at->toISOString(),
                ]),
            'alerts' => Alert::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->orderBy('created_at')
                ->get(['id', 'type', 'severity', 'source', 'data', 'is_silent', 'review_status', 'reviewed_at', 'read_at', 'resolved_at', 'created_at'])
                ->map(fn ($a) => [
                    'id'            => $a->id,
                    'type'          => $a->type,
                    'severity'      => $a->severity,
                    'source'        => $a->source,
                    'data'          => $a->data,
                    'is_silent'     => $a->is_silent,
                    'review_status' => $a->review_status,
                    'reviewed_at'   => $a->reviewed_at?->toISOString(),
                    'read_at'       => $a->read_at?->toISOString(),
                    'resolved_at'   => $a->resolved_at?->toISOString(),
                    'created_at'    => $a->created_at->toISOString(),
                ]),
        ];

        $filename = 'nexstage-export-' . $workspace->slug . '-' . now()->format('Y-m-d') . '.json';

        return response()->json($bundle)
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function resolveUserRole(Request $request, Workspace $workspace): string
    {
        return $workspace->workspaceUsers()
            ->where('user_id', $request->user()->id)
            ->value('role') ?? 'member';
    }
}
