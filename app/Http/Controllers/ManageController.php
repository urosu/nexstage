<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProductCostImportAction;
use App\Jobs\ReclassifyOrdersForMappingJob;
use App\Models\AdInsight;
use App\Models\Campaign;
use App\Models\ChannelMapping;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\Attribution\ChannelClassifierService;
use App\Services\WorkspaceContext;
use Database\Seeders\ChannelMappingsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles the /manage section — tools for workspace owners to improve data quality.
 *
 * Routes:
 *   GET    /manage/tag-generator              → Tag Generator (UTM builder for ad URLs)
 *   GET    /manage/naming-convention          → Read-only explainer + parse status table
 *   GET    /manage/channel-mappings           → Workspace channel mapping CRUD page (16.7)
 *   POST   /manage/channel-mappings           → Create a workspace-scoped override
 *   PUT    /manage/channel-mappings/{id}      → Update a workspace-scoped override
 *   DELETE /manage/channel-mappings/{id}      → Delete a workspace-scoped override
 *   POST   /manage/channel-mappings/import    → Re-seed global defaults (owner only)
 *   GET    /manage/product-costs              → COGS manual entry page
 *   POST   /manage/product-costs              → Create a manual product cost row
 *   PUT    /manage/product-costs/{id}         → Update a product cost row
 *   DELETE /manage/product-costs/{id}         → Delete a product cost row
 *   POST   /manage/product-costs/import       → CSV bulk import
 *   GET    /manage/product-costs/template     → Download CSV template
 *
 * @see PLANNING.md sections 5, 7, 16.4–16.7
 * Related:
 *   resources/js/Pages/Manage/TagGenerator.tsx
 *   resources/js/Pages/Manage/NamingConvention.tsx
 *   resources/js/Pages/Manage/ChannelMappings.tsx
 *   resources/js/Pages/Manage/ProductCosts.tsx
 */
class ManageController extends Controller
{
    /**
     * UTM Tag Generator — surfaces campaign names and ad templates for building
     * properly tagged ad URLs.
     *
     * Passes campaign names so the user can copy-paste them into the UTM builder.
     * No server-side URL construction — all preview logic is in the frontend.
     */
    public function tagGenerator(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        // Surface connected campaign names so the user can pick them in the form.
        // platform lives on ad_accounts, not campaigns — join to fetch it.
        $campaigns = Campaign::withoutGlobalScopes()
            ->where('campaigns.workspace_id', $workspaceId)
            ->whereIn('campaigns.status', ['active', 'paused', 'archived'])
            ->join('ad_accounts', 'ad_accounts.id', '=', 'campaigns.ad_account_id')
            ->select(['campaigns.id', 'campaigns.name', 'ad_accounts.platform'])
            ->orderBy('campaigns.name')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'platform' => $c->platform])
            ->all();

        return Inertia::render('Manage/TagGenerator', [
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * Naming convention explainer (read-only).
     *
     * Shows the fixed `|` template, grouped parse status buckets for the workspace's
     * campaigns, and a 30-day coverage badge. The page is intentionally read-only:
     * users fix campaign names inside Facebook/Google Ads, and `parsed_convention`
     * updates on the next sync via {@see \App\Services\CampaignNameParserService}.
     *
     * Coverage denominator = campaigns with spend in the last 30 days.
     * Numerator = same, filtered to parsed_convention.parse_status = 'clean'.
     *
     * @see PLANNING.md section 16.5
     */
    public function namingConvention(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        // Pull all campaigns with their parsed convention + platform + 30-day spend.
        // The spend subquery is scoped to level=campaign and filters out hourly rows
        // (per CLAUDE.md: never SUM across ad_insights levels).
        $since = now()->subDays(30)->toDateString();

        $spendSub = AdInsight::withoutGlobalScopes()
            ->select('campaign_id', DB::raw('COALESCE(SUM(spend_in_reporting_currency), 0) AS spend_30d'))
            ->where('workspace_id', $workspaceId)
            ->where('level', 'campaign')
            ->whereNull('hour')
            ->where('date', '>=', $since)
            ->groupBy('campaign_id');

        // LOWER() match — FB/Google return mixed casing ("ACTIVE", "active", "enabled"),
        // same idiom CampaignsController uses (see CampaignsController.php:314).
        $rows = Campaign::withoutGlobalScopes()
            ->where('campaigns.workspace_id', $workspaceId)
            ->whereRaw("LOWER(campaigns.status) IN ('active','enabled','delivering','paused','inactive','disabled','archived')")
            ->join('ad_accounts', 'ad_accounts.id', '=', 'campaigns.ad_account_id')
            ->leftJoinSub($spendSub, 'spend', fn ($j) => $j->on('spend.campaign_id', '=', 'campaigns.id'))
            ->select([
                'campaigns.id',
                'campaigns.name',
                'campaigns.parsed_convention',
                'ad_accounts.platform',
                DB::raw('COALESCE(spend.spend_30d, 0) AS spend_30d'),
            ])
            ->orderBy('campaigns.name')
            ->get();

        // Bucket the campaigns by parse_status. Missing parsed_convention (older rows
        // that haven't been re-synced) fall into the 'minimal' bucket — these look
        // identical to single-field names from the user's perspective.
        $clean   = [];
        $partial = [];
        $minimal = [];

        $coverageDenom = 0;
        $coverageNum   = 0;

        foreach ($rows as $row) {
            $pc = is_array($row->parsed_convention) ? $row->parsed_convention : [];
            $status = $pc['parse_status'] ?? 'minimal';
            $spend  = (float) $row->spend_30d;

            $item = [
                'id'           => (int) $row->id,
                'name'         => (string) $row->name,
                'platform'     => (string) $row->platform,
                'spend_30d'    => $spend,
                'country'      => $pc['country']      ?? null,
                'campaign'     => $pc['campaign']     ?? null,
                'raw_target'   => $pc['raw_target']   ?? null,
                'target_type'  => $pc['target_type']  ?? null,
                'target_slug'  => $pc['target_slug']  ?? null,
                'shape'        => $pc['shape']        ?? null,
            ];

            if ($spend > 0) {
                $coverageDenom++;
                if ($status === 'clean') {
                    $coverageNum++;
                }
            }

            match ($status) {
                'clean'   => $clean[]   = $item,
                'partial' => $partial[] = $item,
                default   => $minimal[] = $item,
            };
        }

        $coveragePct = $coverageDenom > 0
            ? (int) round($coverageNum / $coverageDenom * 100)
            : null;

        return Inertia::render('Manage/NamingConvention', [
            'buckets' => [
                'clean'   => $clean,
                'partial' => $partial,
                'minimal' => $minimal,
            ],
            'coverage' => [
                'percent'     => $coveragePct,
                'numerator'   => $coverageNum,
                'denominator' => $coverageDenom,
            ],
        ]);
    }

    /**
     * Workspace-facing channel mappings page.
     *
     * Shows workspace-scoped overrides (editable) and the global seed rows
     * (read-only reference). A workspace override wins at classify time when
     * its (utm_source, utm_medium) pair matches a global row.
     *
     * Also surfaces the top unclassified (source, medium) pairs from the last
     * 90 days of orders so the user can map them directly.
     *
     * @see PLANNING.md section 16.7
     */
    public function channelMappings(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $workspaceRows = ChannelMapping::where('workspace_id', $workspaceId)
            ->orderBy('channel_type')
            ->orderBy('utm_source_pattern')
            ->orderBy('utm_medium_pattern')
            ->get()
            ->map(fn (ChannelMapping $m) => [
                'id'                 => $m->id,
                'utm_source_pattern' => $m->utm_source_pattern,
                'utm_medium_pattern' => $m->utm_medium_pattern,
                'channel_name'       => $m->channel_name,
                'channel_type'       => $m->channel_type,
                'is_global'          => false,
            ])
            ->all();

        $globalRows = ChannelMapping::whereNull('workspace_id')
            ->orderBy('channel_type')
            ->orderBy('utm_source_pattern')
            ->orderBy('utm_medium_pattern')
            ->get()
            ->map(fn (ChannelMapping $m) => [
                'id'                 => $m->id,
                'utm_source_pattern' => $m->utm_source_pattern,
                'utm_medium_pattern' => $m->utm_medium_pattern,
                'channel_name'       => $m->channel_name,
                'channel_type'       => $m->channel_type,
                'is_global'          => true,
            ])
            ->all();

        // Top unclassified (source, medium) pairs — last 90 days, in this workspace.
        // "Unclassified" = attribution parsed (pys/wc_native) but channel not resolved.
        $unrecognized = DB::select(
            <<<'SQL'
                SELECT
                    LOWER(attribution_last_touch->>'source')  AS source,
                    LOWER(attribution_last_touch->>'medium')  AS medium,
                    COUNT(*)                                   AS order_count,
                    COALESCE(SUM(total_in_reporting_currency), 0) AS revenue
                FROM orders
                WHERE workspace_id = ?
                  AND status IN ('completed', 'processing')
                  AND attribution_source IN ('pys', 'wc_native')
                  AND attribution_last_touch IS NOT NULL
                  AND attribution_last_touch->>'source' IS NOT NULL
                  AND (attribution_last_touch->>'channel' IS NULL OR attribution_last_touch->>'channel' = '')
                  AND occurred_at >= NOW() - INTERVAL '90 days'
                GROUP BY 1, 2
                ORDER BY order_count DESC
                LIMIT 20
            SQL,
            [$workspaceId],
        );

        return Inertia::render('Manage/ChannelMappings', [
            'workspace_mappings' => $workspaceRows,
            'global_mappings'    => $globalRows,
            'unrecognized'       => array_map(fn ($r) => [
                'source'      => $r->source,
                'medium'      => $r->medium,
                'order_count' => (int) $r->order_count,
                'revenue'     => round((float) $r->revenue, 2),
            ], $unrecognized),
        ]);
    }

    /**
     * Create a workspace-scoped channel mapping override.
     *
     * Dispatches {@see ReclassifyOrdersForMappingJob} to re-stamp historical
     * orders whose attribution_last_touch matches the new (source, medium) pair.
     *
     * @see PLANNING.md section 16.7
     */
    public function storeChannelMapping(Request $request): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

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
                'workspace_id'       => $workspaceId,
                'utm_source_pattern' => $source,
                'utm_medium_pattern' => $medium,
                'channel_name'       => $validated['channel_name'],
                'channel_type'       => $validated['channel_type'],
                'is_global'          => false,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), '23505')) {
                return back()->withErrors([
                    'utm_source_pattern' => 'A mapping for this source/medium combination already exists in this workspace.',
                ]);
            }
            throw $e;
        }

        $this->invalidateMappingCache($workspaceId);

        ReclassifyOrdersForMappingJob::dispatch(
            $workspaceId,
            $source,
            $medium,
            $validated['channel_name'],
            $validated['channel_type'],
        );

        return back()->with('success', "Mapping created. Historical orders will be reclassified in the background.");
    }

    /**
     * Update an existing workspace-scoped channel mapping.
     *
     * Uses WorkspaceContext (set by SetActiveWorkspace middleware) rather than
     * implicit Workspace model binding, because SetActiveWorkspace calls
     * forgetParameter('workspace') before SubstituteBindings runs.
     */
    public function updateChannelMapping(Request $request, ChannelMapping $channelMapping): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        abort_unless($channelMapping->workspace_id === $workspaceId, 404);

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
                    'utm_source_pattern' => 'A mapping for this source/medium combination already exists in this workspace.',
                ]);
            }
            throw $e;
        }

        $this->invalidateMappingCache($workspaceId);

        ReclassifyOrdersForMappingJob::dispatch(
            $workspaceId,
            $source,
            $medium,
            $validated['channel_name'],
            $validated['channel_type'],
        );

        return back()->with('success', 'Mapping updated. Historical orders will be reclassified in the background.');
    }

    /**
     * Delete a workspace-scoped channel mapping override.
     *
     * Historical orders keep their stamped channel values until the next parser
     * pass — deleting an override does not retroactively strip classifications.
     *
     * Uses WorkspaceContext rather than implicit Workspace model binding — see
     * updateChannelMapping() for the reason.
     */
    public function destroyChannelMapping(ChannelMapping $channelMapping): RedirectResponse
    {
        abort_unless($channelMapping->workspace_id === app(WorkspaceContext::class)->id(), 404);

        $name        = $channelMapping->channel_name;
        $workspaceId = $channelMapping->workspace_id;
        $channelMapping->delete();

        $this->invalidateMappingCache($workspaceId);

        return back()->with('success', "Mapping deleted: {$name}");
    }

    /**
     * Re-seed global channel mapping defaults.
     *
     * Owner-only. Runs {@see ChannelMappingsSeeder} which truncates and re-inserts
     * the ~40 global rows. Workspace overrides are preserved by the seeder's
     * `workspace_id IS NULL` clause.
     */
    public function importChannelMappingDefaults(Request $request): RedirectResponse
    {
        $user = $request->user();
        $workspaceId = app(WorkspaceContext::class)->id();

        // Owner check — mirrors the pattern used in WorkspaceSettingsController.
        $role = DB::table('workspace_users')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->value('role');

        abort_unless($role === 'owner', 403, 'Only workspace owners can re-seed defaults.');

        (new ChannelMappingsSeeder())->run();

        // Global rows are shared across all workspaces — bust every workspace's cache plus
        // the shared global-rows cache key used by ChannelClassifierService.
        $workspaceKeys = Workspace::query()->pluck('id')
            ->map(fn (int $id) => ChannelClassifierService::cacheKey($id))
            ->all();

        Cache::deleteMultiple(array_merge($workspaceKeys, [ChannelClassifierService::GLOBAL_CACHE_KEY]));

        return back()->with('success', 'Global channel mapping defaults re-seeded.');
    }

    private function invalidateMappingCache(int $workspaceId): void
    {
        Cache::deleteMultiple([
            ChannelClassifierService::cacheKey($workspaceId),
            ChannelClassifierService::GLOBAL_CACHE_KEY,
        ]);
    }

    // ── Product Costs ─────────────────────────────────────────────────────────

    /**
     * Manual COGS entry page.
     *
     * Displays all product_cost rows for the workspace alongside a store and product
     * list for the entry form. CogsReaderService reads this table as a last-resort
     * fallback after WooCommerce plugin meta and Shopify inventory snapshots.
     *
     * @see PLANNING.md sections 5, 7
     */
    public function productCosts(): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $costs = ProductCost::with('store')
            ->orderBy('product_external_id')
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (ProductCost $pc) => [
                'id'                  => $pc->id,
                'store_id'            => $pc->store_id,
                'store_name'          => $pc->store?->name,
                'product_external_id' => $pc->product_external_id,
                'unit_cost'           => (float) $pc->unit_cost,
                'currency'            => $pc->currency,
                'effective_from'      => $pc->effective_from?->toDateString(),
                'effective_to'        => $pc->effective_to?->toDateString(),
                'source'              => $pc->source,
            ])
            ->all();

        $stores = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select(['id', 'name', 'currency'])
            ->orderBy('name')
            ->get()
            ->map(fn (Store $s) => [
                'id'       => $s->id,
                'name'     => $s->name,
                'currency' => $s->currency,
            ])
            ->all();

        // Limit to 2 000 to avoid memory pressure on large catalogues.
        $products = Product::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select(['id', 'external_id', 'name', 'sku', 'store_id'])
            ->orderBy('name')
            ->limit(2000)
            ->get()
            ->map(fn (Product $p) => [
                'id'          => $p->id,
                'external_id' => $p->external_id,
                'name'        => $p->name,
                'sku'         => $p->sku,
                'store_id'    => $p->store_id,
            ])
            ->all();

        return Inertia::render('Manage/ProductCosts', [
            'costs'    => $costs,
            'stores'   => $stores,
            'products' => $products,
        ]);
    }

    /**
     * Create manual product cost rows — one per selected product_external_id.
     *
     * Accepts product_external_ids as an array so the UI can apply the same
     * cost to multiple products in a single submission.
     */
    public function storeProductCost(Request $request): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'store_id'               => ['required', 'integer'],
            'product_external_ids'   => ['required', 'array', 'min:1'],
            'product_external_ids.*' => ['required', 'string', 'max:255'],
            'unit_cost'              => ['required', 'numeric', 'min:0'],
            'currency'               => ['required', 'string', 'size:3'],
            'effective_from'         => ['nullable', 'date'],
            'effective_to'           => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        abort_unless(
            Store::withoutGlobalScopes()
                ->where('id', $validated['store_id'])
                ->where('workspace_id', $workspaceId)
                ->exists(),
            404,
        );

        $currency = strtoupper($validated['currency']);
        $now      = now();
        $rows     = [];

        foreach ($validated['product_external_ids'] as $externalId) {
            $rows[] = [
                'workspace_id'        => $workspaceId,
                'store_id'            => $validated['store_id'],
                'product_external_id' => $externalId,
                'unit_cost'           => $validated['unit_cost'],
                'currency'            => $currency,
                'effective_from'      => $validated['effective_from'],
                'effective_to'        => $validated['effective_to'] ?? null,
                'source'              => 'manual',
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        ProductCost::insert($rows);
        $count = count($rows);

        $label = $count === 1 ? '1 product cost saved.' : "{$count} product costs saved.";

        return back()->with('success', $label);
    }

    /**
     * Update an existing product cost row.
     *
     * product_external_id and store_id are not editable after creation —
     * delete and re-create if the product needs to change.
     */
    public function updateProductCost(Request $request, ProductCost $productCost): RedirectResponse
    {
        abort_unless($productCost->workspace_id === app(WorkspaceContext::class)->id(), 404);

        $validated = $request->validate([
            'unit_cost'      => ['required', 'numeric', 'min:0'],
            'currency'       => ['required', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to'   => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $productCost->update([
            'unit_cost'      => $validated['unit_cost'],
            'currency'       => strtoupper($validated['currency']),
            'effective_from' => $validated['effective_from'],
            'effective_to'   => $validated['effective_to'] ?? null,
        ]);

        return back()->with('success', 'Product cost updated.');
    }

    /**
     * Delete a product cost row.
     */
    public function destroyProductCost(ProductCost $productCost): RedirectResponse
    {
        abort_unless($productCost->workspace_id === app(WorkspaceContext::class)->id(), 404);

        $productCost->delete();

        return back()->with('success', 'Product cost deleted.');
    }

    /**
     * Bulk delete product cost rows by ID.
     *
     * Scoped to the active workspace so IDs from other workspaces are silently ignored.
     */
    public function bulkDestroyProductCosts(Request $request): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = ProductCost::whereIn('id', $validated['ids'])
            ->where('workspace_id', $workspaceId)
            ->delete();

        return back()->with('success', "{$deleted} product cost(s) deleted.");
    }

    /**
     * Bulk import product costs from a CSV file.
     *
     * Delegates to {@see ProductCostImportAction}. Result summary is flashed
     * back to the frontend as `import_result` and displayed in the CSV dialog.
     */
    public function importProductCosts(Request $request): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $workspace   = Workspace::findOrFail($workspaceId);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $result = (new ProductCostImportAction())->execute($request->file('file'), $workspace);

        return back()->with('import_result', $result);
    }

    /**
     * Stream a sample CSV template the user can fill in and re-upload.
     */
    public function productCostTemplate(): StreamedResponse
    {
        $filename = 'product-costs-template.csv';

        return response()->stream(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['product_external_id', 'sku', 'unit_cost', 'currency', 'effective_from', 'effective_to']);
            fputcsv($out, ['123', '', '9.99', 'USD', date('Y-m-d'), '']);
            fputcsv($out, ['', 'my-product-sku', '14.50', 'USD', date('Y') . '-01-01', '']);
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
