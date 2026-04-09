<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\PurgeDeletedWorkspaceJob;
use App\Models\Store;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PurgeDeletedWorkspaceJobTest extends TestCase
{
    use RefreshDatabase;

    private function runJob(): void
    {
        (new PurgeDeletedWorkspaceJob())->handle();
    }

    private function softDeleteWorkspace(Workspace $workspace, int $daysAgo): void
    {
        DB::table('workspaces')
            ->where('id', $workspace->id)
            ->update(['deleted_at' => now()->subDays($daysAgo)->toDateTimeString()]);
    }

    public function test_purges_workspace_deleted_more_than_30_days_ago(): void
    {
        $workspace = Workspace::factory()->create();
        $this->softDeleteWorkspace($workspace, 31);

        $this->runJob();

        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
    }

    public function test_does_not_purge_workspace_deleted_less_than_30_days_ago(): void
    {
        $workspace = Workspace::factory()->create();
        $this->softDeleteWorkspace($workspace, 29);

        $this->runJob();

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    }

    public function test_does_not_purge_active_workspaces(): void
    {
        $workspace = Workspace::factory()->create(); // no deleted_at

        $this->runJob();

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    }

    public function test_logs_purged_workspace(): void
    {
        Log::spy();

        $workspace = Workspace::factory()->create();
        $this->softDeleteWorkspace($workspace, 31);

        $this->runJob();

        Log::shouldHaveReceived('info')
            ->with('Workspace purged', \Mockery::on(fn ($ctx) => ($ctx['workspace_id'] ?? null) === $workspace->id));
    }

    public function test_cascade_deletes_related_data(): void
    {
        $workspace = Workspace::factory()->create();
        $store     = Store::factory()->create(['workspace_id' => $workspace->id]);

        // Insert an order bypassing WorkspaceScope
        DB::table('orders')->insert([
            'workspace_id'  => $workspace->id,
            'store_id'      => $store->id,
            'external_id'   => 'order-cascade',
            'status'        => 'completed',
            'currency'      => 'EUR',
            'total'         => 100,
            'subtotal'      => 90,
            'tax'           => 10,
            'shipping'      => 5,
            'discount'      => 0,
            'occurred_at'   => now(),
            'synced_at'     => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->softDeleteWorkspace($workspace, 31);
        $this->runJob();

        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseMissing('stores', ['id' => $store->id]);
        $this->assertDatabaseMissing('orders', ['workspace_id' => $workspace->id]);
    }

    public function test_only_purges_workspaces_past_grace_period(): void
    {
        $oldWorkspace  = Workspace::factory()->create();
        $recentWorkspace = Workspace::factory()->create();

        $this->softDeleteWorkspace($oldWorkspace, 31);
        $this->softDeleteWorkspace($recentWorkspace, 10);

        $this->runJob();

        $this->assertDatabaseMissing('workspaces', ['id' => $oldWorkspace->id]);
        $this->assertDatabaseHas('workspaces', ['id' => $recentWorkspace->id]);
    }
}
