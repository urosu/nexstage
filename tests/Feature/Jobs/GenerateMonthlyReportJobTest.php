<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateMonthlyReportJob;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\MonthlyReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Feature / smoke tests for the monthly PDF report pipeline added in Phase 1.6.
 *
 * Tests three layers:
 *   1. MonthlyReportService::build() — data assembly, including COGS flag
 *   2. GenerateMonthlyReportJob::handle() — PDF render + Storage write (PDF mocked)
 *   3. GET /{workspace}/insights/monthly-report/{year}/{month} — HTTP download endpoint
 *
 * The dompdf render is mocked in jobs and controller tests to avoid font/render
 * dependencies in CI. MonthlyReportService is tested in isolation without dompdf.
 *
 * @see app/Services/MonthlyReportService
 * @see app/Jobs/GenerateMonthlyReportJob
 * @see app/Http/Controllers/InsightsController::downloadMonthlyReport
 * @see PLANNING.md section 12.5 (Monthly PDF reports)
 */
class GenerateMonthlyReportJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Store $store;
    private User $user;
    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $this->user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $this->store = Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);

        // Use March 2026 as a closed past month so snapshot dates are predictable
        $this->month = CarbonImmutable::parse('2026-03-01');
    }

    // ── MonthlyReportService ─────────────────────────────────────────────────

    public function test_build_returns_required_keys(): void
    {
        $service = app(MonthlyReportService::class);
        $data    = $service->build($this->workspace->id, $this->month);

        $this->assertArrayHasKey('workspace_name',      $data);
        $this->assertArrayHasKey('reporting_currency',  $data);
        $this->assertArrayHasKey('month_label',         $data);
        $this->assertArrayHasKey('period',              $data);
        $this->assertArrayHasKey('hero',                $data);
        $this->assertArrayHasKey('ads',                 $data);
        $this->assertArrayHasKey('cogs',                $data);
        $this->assertArrayHasKey('top_products',        $data);
        $this->assertArrayHasKey('generated_at',        $data);
    }

    public function test_hero_revenue_sums_daily_snapshots(): void
    {
        // Seed two daily_snapshots for days within March 2026
        DB::table('daily_snapshots')->insert([
            'workspace_id'        => $this->workspace->id,
            'store_id'            => $this->store->id,
            'date'                => '2026-03-10',
            'revenue'             => 1000.00,
            'revenue_native'      => 1000.00,
            'orders_count'        => 5,
            'aov'                 => 200.00,
            'items_sold'          => 8,
            'items_per_order'     => 1.60,
            'new_customers'       => 3,
            'returning_customers' => 2,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
        DB::table('daily_snapshots')->insert([
            'workspace_id'        => $this->workspace->id,
            'store_id'            => $this->store->id,
            'date'                => '2026-03-15',
            'revenue'             => 500.00,
            'revenue_native'      => 500.00,
            'orders_count'        => 2,
            'aov'                 => 250.00,
            'items_sold'          => 3,
            'items_per_order'     => 1.50,
            'new_customers'       => 1,
            'returning_customers' => 1,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $service = app(MonthlyReportService::class);
        $data    = $service->build($this->workspace->id, $this->month);

        $this->assertEqualsWithDelta(1500.0, $data['hero']['revenue'], 0.01);
        $this->assertSame(7, (int) $data['hero']['orders']);
    }

    public function test_cogs_configured_false_when_no_order_items_have_unit_cost(): void
    {
        $service = app(MonthlyReportService::class);
        $data    = $service->build($this->workspace->id, $this->month);

        $this->assertFalse($data['cogs']['configured']);
    }

    // ── GenerateMonthlyReportJob ─────────────────────────────────────────────

    public function test_job_writes_pdf_to_storage(): void
    {
        Storage::fake('local');

        // Mock PDF facade so dompdf is not invoked in CI
        $pdfMock = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('output')->andReturn('%PDF-1.4 smoke test content');

        Pdf::shouldReceive('loadView')->andReturn($pdfMock);

        $monthStart = $this->month->toDateString();
        (new GenerateMonthlyReportJob($this->workspace->id, $monthStart))
            ->handle(app(MonthlyReportService::class));

        $expectedPath = sprintf(
            'reports/monthly/%d/%s.pdf',
            $this->workspace->id,
            '2026-03',
        );

        Storage::disk('local')->assertExists($expectedPath);
    }

    // ── InboxController download endpoint ────────────────────────────────────

    public function test_download_endpoint_returns_pdf_response(): void
    {
        // Mock PDF facade — avoids dompdf render in tests
        $pdfMock = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('download')
            ->andReturn(response('PDF content', 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename=nexstage-monthly-2026-03.pdf',
            ]));

        Pdf::shouldReceive('loadView')->andReturn($pdfMock);

        $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/inbox/monthly-report/2026/3")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_download_endpoint_requires_authentication(): void
    {
        $this->get("/{$this->workspace->slug}/inbox/monthly-report/2026/3")
            ->assertRedirect();
    }
}
