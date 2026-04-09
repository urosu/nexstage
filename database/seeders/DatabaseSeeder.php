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
        $this->call([
            UserSeeder::class,
            WorkspaceSeeder::class,
        ]);

        // Set WorkspaceContext so all subsequent seeders can query scoped models
        $workspaceId = (int) DB::table('workspaces')->value('id');
        app(WorkspaceContext::class)->set($workspaceId);

        $this->call([
            FxRateSeeder::class,
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
