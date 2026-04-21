<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SyncAdInsightsJob;
use App\Models\AdAccount;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Verifies that SyncAdInsightsJob lands on the correct provider-specific queue.
 *
 * PLANNING section 22: a rate-limited Facebook job must not block Google Ads sync.
 * This test is the machine-checkable version of that requirement.
 */
class SyncAdInsightsJobQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_facebook_account_dispatches_to_sync_facebook_queue(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $account   = AdAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform'     => 'facebook',
        ]);

        SyncAdInsightsJob::dispatch($account->id, $workspace->id, 'facebook');

        Queue::assertPushedOn('sync-facebook', SyncAdInsightsJob::class);
    }

    public function test_google_account_dispatches_to_sync_google_ads_queue(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $account   = AdAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform'     => 'google',
        ]);

        SyncAdInsightsJob::dispatch($account->id, $workspace->id, 'google');

        Queue::assertPushedOn('sync-google-ads', SyncAdInsightsJob::class);
    }

    public function test_facebook_is_the_default_platform_queue(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $account   = AdAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform'     => 'facebook',
        ]);

        // Dispatch without explicit platform (backwards-compatible default)
        SyncAdInsightsJob::dispatch($account->id, $workspace->id);

        Queue::assertPushedOn('sync-facebook', SyncAdInsightsJob::class);
    }

    public function test_facebook_and_google_jobs_land_on_separate_queues(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();

        SyncAdInsightsJob::dispatch(1, $workspace->id, 'facebook');
        SyncAdInsightsJob::dispatch(2, $workspace->id, 'google');

        // Each platform must land on its own queue — isolation is the entire point of section 22.
        // A rate-limited Facebook job releases workers only on sync-facebook,
        // leaving sync-google-ads unblocked.
        Queue::assertPushedOn('sync-facebook', SyncAdInsightsJob::class);
        Queue::assertPushedOn('sync-google-ads', SyncAdInsightsJob::class);
    }
}
