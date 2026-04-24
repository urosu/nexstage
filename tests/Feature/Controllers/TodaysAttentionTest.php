<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for DashboardController::computeTodaysAttention().
 *
 * Verifies:
 *   - attention_items is empty when no alerts or recommendations exist
 *   - Unresolved warning/critical alerts appear first
 *   - info alerts are excluded (severity filter)
 *   - Recommendations fill remaining slots after alerts
 *   - Total is capped at 5 items
 *   - Resolved alerts are excluded
 *
 * @see app/Http/Controllers/DashboardController::computeTodaysAttention()
 * @see PROGRESS.md Phase 3.6 — Today's Attention generator
 */
class TodaysAttentionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $this->user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);
    }

    private function getAttentionItems(): array
    {
        $captured = [];
        $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}")
            ->assertInertia(function ($page) use (&$captured) {
                $captured = $page->toArray()['props']['attention_items'] ?? [];
                return $page;
            });

        return $captured;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function empty_when_no_alerts_and_no_recommendations(): void
    {
        $items = $this->getAttentionItems();
        $this->assertEmpty($items);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unresolved_warning_alert_appears_in_items(): void
    {
        $this->insertAlert('sync_failed', 'warning');

        $items = $this->getAttentionItems();

        $this->assertCount(1, $items);
        $this->assertSame('warning', $items[0]['severity']);
        $this->assertNotEmpty($items[0]['text']);
        $this->assertNotEmpty($items[0]['href']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function info_alerts_are_excluded(): void
    {
        $this->insertAlert('sync_info', 'info');

        $items = $this->getAttentionItems();

        $this->assertEmpty($items, 'info-severity alerts must not appear in Today\'s Attention');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function resolved_alerts_are_excluded(): void
    {
        $this->insertAlert('sync_failed', 'warning', resolved: true);

        $items = $this->getAttentionItems();

        $this->assertEmpty($items, 'resolved alerts must not appear');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function recommendations_fill_slots_after_alerts(): void
    {
        $this->insertAlert('sync_failed', 'warning');
        $this->insertRecommendation('Check your campaigns');
        $this->insertRecommendation('Boost organic queries');

        $items = $this->getAttentionItems();

        $this->assertCount(3, $items);
        // Alert is first
        $this->assertSame('warning', $items[0]['severity']);
        // Recs fill remaining slots as 'info'
        $this->assertSame('info', $items[1]['severity']);
        $this->assertSame('info', $items[2]['severity']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function total_is_capped_at_five(): void
    {
        // Insert 3 alerts + 4 recommendations = 7 potential items
        for ($i = 0; $i < 3; $i++) {
            $this->insertAlert("type_{$i}", 'warning');
        }
        for ($i = 0; $i < 4; $i++) {
            $this->insertRecommendation("Recommendation {$i}");
        }

        $items = $this->getAttentionItems();

        $this->assertCount(5, $items, 'attention_items must never exceed 5');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function critical_alerts_appear_before_warning_alerts(): void
    {
        $this->insertAlert('low_roas', 'warning');
        $this->insertAlert('token_expired', 'critical');

        $items = $this->getAttentionItems();

        $this->assertCount(2, $items);
        $this->assertSame('critical', $items[0]['severity']);
        $this->assertSame('warning',  $items[1]['severity']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function insertAlert(string $type, string $severity, bool $resolved = false): void
    {
        DB::table('alerts')->insert([
            'workspace_id' => $this->workspace->id,
            'type'         => $type,
            'severity'     => $severity,
            'is_silent'    => false,
            'data'         => '{}',
            'resolved_at'  => $resolved ? now() : null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function insertRecommendation(string $title, int $priority = 100): void
    {
        DB::table('recommendations')->insert([
            'workspace_id' => $this->workspace->id,
            'type'         => 'test_recommendation',
            'priority'     => $priority,
            'title'        => $title,
            'body'         => 'Test body',
            'status'       => 'open',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
