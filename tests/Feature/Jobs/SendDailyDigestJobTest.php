<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\DispatchDailyDigestsJob;
use App\Jobs\SendDailyDigestJob;
use App\Mail\DailyDigestMail;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Scopes\WorkspaceScope;
use App\Services\NarrativeTemplateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for Phase 3.7 — Daily Digest.
 *
 * Covers:
 *   1. Opted-in workspace owner receives the digest.
 *   2. Owner with no digest preference receives nothing.
 *   3. DispatchDailyDigestsJob dispatches only for workspaces at local 5am.
 *   4. weekly_digest-only users skip non-Monday sends.
 *
 * @see App\Jobs\SendDailyDigestJob
 * @see App\Jobs\DispatchDailyDigestsJob
 * @see PROGRESS.md Phase 3.7 — Daily digest
 */
class SendDailyDigestJobTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email_verified_at' => now()]);

        $this->workspace = Workspace::factory()->create([
            'owner_id'      => $this->owner->id,
            'timezone'      => 'America/Los_Angeles', // UTC-7 summer — never at 5am when tests fake UTC 03:00/05:00
            'has_ads'       => false,
            'has_gsc'       => false,
            'trial_ends_at' => now()->addDays(30),
        ]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $this->owner->id,
            'workspace_id' => $this->workspace->id,
        ]);
    }

    // ── SendDailyDigestJob ───────────────────────────────────────────────────

    public function test_digest_is_sent_to_opted_in_owner(): void
    {
        Mail::fake();

        NotificationPreference::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id'  => $this->workspace->id,
            'user_id'       => $this->owner->id,
            'channel'       => 'email',
            'severity'      => 'high',
            'enabled'       => true,
            'delivery_mode' => 'daily_digest',
        ]);

        $job = new SendDailyDigestJob($this->workspace->id, isMonday: false);
        $job->handle(app(NarrativeTemplateService::class));

        Mail::assertQueued(DailyDigestMail::class, function (DailyDigestMail $mail): bool {
            return $mail->hasTo($this->owner->email)
                && $mail->isWeekly === false;
        });
    }

    public function test_digest_is_not_sent_when_opted_out(): void
    {
        Mail::fake();

        // No notification_preferences rows at all — fully opted out.

        $job = new SendDailyDigestJob($this->workspace->id, isMonday: false);
        $job->handle(app(NarrativeTemplateService::class));

        Mail::assertNotQueued(DailyDigestMail::class);
    }

    public function test_digest_is_not_sent_when_email_preference_is_disabled(): void
    {
        Mail::fake();

        NotificationPreference::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id'  => $this->workspace->id,
            'user_id'       => $this->owner->id,
            'channel'       => 'email',
            'severity'      => 'high',
            'enabled'       => false,               // explicitly disabled
            'delivery_mode' => 'daily_digest',
        ]);

        $job = new SendDailyDigestJob($this->workspace->id, isMonday: false);
        $job->handle(app(NarrativeTemplateService::class));

        Mail::assertNotQueued(DailyDigestMail::class);
    }

    public function test_weekly_digest_sends_on_monday(): void
    {
        Mail::fake();

        NotificationPreference::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id'  => $this->workspace->id,
            'user_id'       => $this->owner->id,
            'channel'       => 'email',
            'severity'      => 'high',
            'enabled'       => true,
            'delivery_mode' => 'weekly_digest',
        ]);

        $job = new SendDailyDigestJob($this->workspace->id, isMonday: true);
        $job->handle(app(NarrativeTemplateService::class));

        Mail::assertQueued(DailyDigestMail::class, function (DailyDigestMail $mail): bool {
            return $mail->hasTo($this->owner->email)
                && $mail->isWeekly === true;
        });
    }

    public function test_weekly_digest_is_skipped_on_non_monday(): void
    {
        Mail::fake();

        NotificationPreference::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id'  => $this->workspace->id,
            'user_id'       => $this->owner->id,
            'channel'       => 'email',
            'severity'      => 'high',
            'enabled'       => true,
            'delivery_mode' => 'weekly_digest',
        ]);

        $job = new SendDailyDigestJob($this->workspace->id, isMonday: false);
        $job->handle(app(NarrativeTemplateService::class));

        Mail::assertNotQueued(DailyDigestMail::class);
    }

    public function test_unverified_owner_does_not_receive_digest(): void
    {
        Mail::fake();

        DB::table('users')->where('id', $this->owner->id)->update(['email_verified_at' => null]);

        NotificationPreference::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id'  => $this->workspace->id,
            'user_id'       => $this->owner->id,
            'channel'       => 'email',
            'severity'      => 'high',
            'enabled'       => true,
            'delivery_mode' => 'daily_digest',
        ]);

        $job = new SendDailyDigestJob($this->workspace->id, isMonday: false);
        $job->handle(app(NarrativeTemplateService::class));

        Mail::assertNotQueued(DailyDigestMail::class);
    }

    // ── DispatchDailyDigestsJob ──────────────────────────────────────────────

    public function test_dispatcher_queues_job_for_workspace_at_local_5am(): void
    {
        Queue::fake();
        Cache::flush();

        // Europe/Paris in CEST (summer) = UTC+2. At UTC 03:00, local time is 05:00.
        Carbon::setTestNow(Carbon::parse('2026-06-15 03:00:00', 'UTC'));

        $workspace = Workspace::factory()->create([
            'timezone'      => 'Europe/Paris',
            'deleted_at'    => null,
            'trial_ends_at' => now()->addDays(30),
            'billing_plan'  => null,
        ]);

        (new DispatchDailyDigestsJob())();

        Queue::assertPushed(SendDailyDigestJob::class, function (SendDailyDigestJob $job) use ($workspace): bool {
            return $job->workspaceId === $workspace->id;
        });

        Carbon::setTestNow();
    }

    public function test_dispatcher_skips_workspace_not_at_5am(): void
    {
        Queue::fake();
        Cache::flush();

        // UTC 03:00 → Europe/Paris is 05:00, but workspace is in UTC so local hour is 3 — skip.
        Carbon::setTestNow(Carbon::parse('2026-06-15 03:00:00', 'UTC'));

        $workspace = Workspace::factory()->create([
            'timezone'      => 'UTC',
            'deleted_at'    => null,
            'trial_ends_at' => now()->addDays(30),
            'billing_plan'  => null,
        ]);

        (new DispatchDailyDigestsJob())();

        Queue::assertNotPushed(SendDailyDigestJob::class, function (SendDailyDigestJob $job) use ($workspace): bool {
            return $job->workspaceId === $workspace->id;
        });

        Carbon::setTestNow();
    }

    public function test_dispatcher_deduplicates_within_same_local_date(): void
    {
        Queue::fake();
        Cache::flush();

        Carbon::setTestNow(Carbon::parse('2026-06-15 05:00:00', 'UTC'));

        $workspace = Workspace::factory()->create([
            'timezone'      => 'UTC',
            'deleted_at'    => null,
            'trial_ends_at' => now()->addDays(30),
            'billing_plan'  => null,
        ]);

        // Run dispatcher twice within the same hour.
        (new DispatchDailyDigestsJob())();
        (new DispatchDailyDigestsJob())();

        // Only one job should have been dispatched despite two runs.
        Queue::assertPushed(SendDailyDigestJob::class, 1);

        Carbon::setTestNow();
    }
}
