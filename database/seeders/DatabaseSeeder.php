<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\WorkspaceContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // These seeders populate lookup/config tables and are safe to run in any environment.
        $this->call([
            ChannelMappingsSeeder::class,
            FxRateSeeder::class,
            CommercialEventsSeeder::class,
            GscCtrBenchmarksSeeder::class,
            CreativeTagSeeder::class,
        ]);

        // Dev/staging only — creates fake users, workspaces, orders, ads, and snapshots.
        // Never run in production.
        if (! app()->environment('production')) {
            $this->call([
                UserSeeder::class,
                WorkspaceSeeder::class,
            ]);

            // Set WorkspaceContext so all subsequent seeders can query scoped models
            $workspaceId = (int) DB::table('workspaces')->value('id');
            app(WorkspaceContext::class)->set($workspaceId);

            $this->call([
                StoreSeeder::class,
                ProductSeeder::class,
                OrderSeeder::class,
                AdSeeder::class,
                SnapshotSeeder::class,
                SearchConsoleSeeder::class,
                SystemSeeder::class,
            ]);
        }
    }
}
