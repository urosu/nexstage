<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AdAccount;
use App\Models\AiSummary;
use App\Models\Alert;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $workspace  = Workspace::first();
        $store      = Store::first();
        $fbAccount  = AdAccount::where('platform', 'facebook')->first();
        $gAccount   = AdAccount::where('platform', 'google')->first();

        // ── Sync logs ─────────────────────────────────────────────────────────
        $syncTypes = [
            ['job_type' => 'SyncStoreOrdersJob',   'syncable_type' => 'App\Models\Store',     'syncable_id' => $store->id],
            ['job_type' => 'SyncAdInsightsJob',     'syncable_type' => 'App\Models\AdAccount', 'syncable_id' => $fbAccount->id],
            ['job_type' => 'SyncAdInsightsJob',     'syncable_type' => 'App\Models\AdAccount', 'syncable_id' => $gAccount->id],
            ['job_type' => 'SyncProductsJob',       'syncable_type' => 'App\Models\Store',     'syncable_id' => $store->id],
            ['job_type' => 'SyncSearchConsoleJob',  'syncable_type' => 'App\Models\SearchConsoleProperty', 'syncable_id' => 1],
            ['job_type' => 'ComputeDailySnapshotJob','syncable_type' => 'App\Models\Store',    'syncable_id' => $store->id],
        ];

        for ($i = 0; $i < 30; $i++) {
            $type    = $syncTypes[array_rand($syncTypes)];
            $started = now()->subHours(rand(1, 72))->subMinutes(rand(0, 59));
            $dur     = rand(3, 180);
            $failed  = mt_rand(0, 100) < 5; // 5% failure rate

            SyncLog::create([
                'workspace_id'      => $workspace->id,
                'syncable_type'     => $type['syncable_type'],
                'syncable_id'       => $type['syncable_id'],
                'job_type'          => $type['job_type'],
                'status'            => $failed ? 'failed' : 'completed',
                'records_processed' => $failed ? null : rand(10, 500),
                'error_message'     => $failed ? 'Connection timeout after 30 seconds' : null,
                'started_at'        => $started,
                'completed_at'      => $failed ? null : $started->copy()->addSeconds($dur),
                'duration_seconds'  => $failed ? null : $dur,
            ]);
        }

        // ── Alerts ────────────────────────────────────────────────────────────
        Alert::create([
            'workspace_id'  => $workspace->id,
            'store_id'      => $store->id,
            'ad_account_id' => null,
            'type'          => 'store_sync_recovered',
            'severity'      => 'info',
            'data'          => ['store_name' => $store->name, 'failures_cleared' => 2],
            'read_at'       => now()->subHours(3),
            'resolved_at'   => now()->subHours(3),
            'created_at'    => now()->subHours(6),
            'updated_at'    => now()->subHours(3),
        ]);

        Alert::create([
            'workspace_id'  => $workspace->id,
            'store_id'      => null,
            'ad_account_id' => $fbAccount->id,
            'type'          => 'token_expiring_soon',
            'severity'      => 'warning',
            'data'          => ['platform' => 'facebook', 'expires_in_days' => 7],
            'read_at'       => null,
            'resolved_at'   => null,
            'created_at'    => now()->subDay(),
            'updated_at'    => now()->subDay(),
        ]);

        Alert::create([
            'workspace_id'  => $workspace->id,
            'store_id'      => $store->id,
            'ad_account_id' => null,
            'type'          => 'revenue_spike',
            'severity'      => 'info',
            'data'          => ['date' => now()->subDays(3)->toDateString(), 'revenue' => 4820.50, 'vs_avg' => '+62%'],
            'read_at'       => null,
            'resolved_at'   => null,
            'created_at'    => now()->subDays(3),
            'updated_at'    => now()->subDays(3),
        ]);

        // ── AI summaries ──────────────────────────────────────────────────────
        $summaries = [
            "Yesterday's performance was strong with €3,240 in revenue across 18 orders, a 12% uplift over the same weekday last week. The Wireless Headphones SKU continued to lead product revenue at €840. Facebook Retargeting delivered the best ROAS at 4.8×, though spend was down slightly. One recommendation: the CH market showed a 34% conversion rate above average — consider increasing budget allocation for that segment.",
            "Revenue came in at €2,890 yesterday, broadly in line with the 7-day average. New customer acquisition was notably softer at 6 vs. the 10-day average of 9, suggesting acquisition campaigns may need creative refresh. Google Search — Brand Keywords maintained strong efficiency at €0.42 CPC. No anomalies detected in order volume or refund rate.",
            "A quieter Sunday with €1,960 revenue and 11 orders. AOV held steady at €178. The main anomaly is a spike in cart abandonment implied by a drop in the processing-to-completed conversion — worth checking WooCommerce checkout flow. Ad spend was paused on Performance Max over the weekend, which likely contributed to the softer day.",
        ];

        foreach ($summaries as $i => $text) {
            AiSummary::create([
                'workspace_id' => $workspace->id,
                'date'         => now()->subDays($i + 1)->toDateString(),
                'summary_text' => $text,
                'model_used'   => 'claude-sonnet-4-6',
                'generated_at' => now()->subDays($i + 1)->setTime(7, 15),
            ]);
        }
    }
}
