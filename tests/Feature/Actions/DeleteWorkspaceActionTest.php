<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\DeleteWorkspaceAction;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class DeleteWorkspaceActionTest extends TestCase
{
    use RefreshDatabase;

    private DeleteWorkspaceAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(DeleteWorkspaceAction::class);
    }

    private function makeMockWorkspace(bool $subscribed = false, bool $hasOpenInvoice = false): Workspace
    {
        $workspace = Workspace::factory()->create();

        $mock = Mockery::mock($workspace)->makePartial();
        $mock->shouldReceive('subscribed')->andReturn($subscribed);

        $invoices = collect([]);
        if ($hasOpenInvoice) {
            $invoice = Mockery::mock(\Laravel\Cashier\Invoice::class);
            $invoice->shouldReceive('isOpen')->andReturn(true);
            $invoices->push($invoice);
        }

        $mock->shouldReceive('invoices')->andReturn($invoices);

        return $mock;
    }

    public function test_soft_deletes_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $mock      = Mockery::mock($workspace)->makePartial();
        $mock->shouldReceive('subscribed')->andReturn(false);
        $mock->shouldReceive('invoices')->andReturn(collect([]));
        $mock->shouldReceive('delete')->andReturnUsing(function () use ($workspace) {
            $workspace->delete();
        });

        $this->action->handle($mock);

        $this->assertSoftDeleted('workspaces', ['id' => $workspace->id]);
    }

    public function test_blocked_if_active_subscription(): void
    {
        $mock = $this->makeMockWorkspace(subscribed: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cancel your subscription');

        $this->action->handle($mock);
    }

    public function test_blocked_if_open_invoice(): void
    {
        $mock = $this->makeMockWorkspace(subscribed: false, hasOpenInvoice: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unpaid invoices');

        $this->action->handle($mock);
    }

    public function test_logs_deletion(): void
    {
        Log::spy();

        $workspace = Workspace::factory()->create();
        $mock      = Mockery::mock($workspace)->makePartial();
        $mock->shouldReceive('subscribed')->andReturn(false);
        $mock->shouldReceive('invoices')->andReturn(collect([]));
        $mock->shouldReceive('delete')->andReturnUsing(function () use ($workspace) {
            $workspace->delete();
        });

        $this->action->handle($mock);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Workspace soft-deleted', Mockery::any());
    }
}
