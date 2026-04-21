<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\UpsertWooCommerceOrderAction;
use App\Actions\UpsertWooCommerceProductAction;
use App\Models\Order;
use App\Models\Store;
use App\Models\WebhookLog;
use App\Scopes\WorkspaceScope;
use App\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes a single WooCommerce webhook delivery.
 *
 * Queue:   critical-webhooks
 * Timeout: 30 s
 * Tries:   5
 * Backoff: [5, 15, 30, 60, 120] s
 *
 * Flow:
 *   1. Set WorkspaceContext so workspace-scoped models resolve correctly.
 *   2. Deduplication: skip if an identical event+entity_id was processed within 24 h.
 *   3. Dispatch to the appropriate handler (upsert or soft-delete).
 *   4. Mark WebhookLog as 'processed' or 'failed'.
 *
 * Failures are re-thrown so Horizon retries the job. The WebhookLog is updated
 * to 'failed' on each terminal failure so the record is never silently lost.
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries   = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30, 60, 120];

    public function __construct(
        private readonly int    $webhookLogId,
        private readonly int    $storeId,
        private readonly int    $workspaceId,
        private readonly string $event,
        /** @var array<string, mixed> */
        private readonly array  $payload,
    ) {
        $this->onQueue('critical-webhooks');
    }

    public function handle(UpsertWooCommerceOrderAction $orderAction, UpsertWooCommerceProductAction $productAction): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $externalEntityId = (string) ($this->payload['id'] ?? '');

        // --- Deduplication ---------------------------------------------------
        if ($externalEntityId !== '' && $this->isDuplicate($externalEntityId)) {
            Log::info('ProcessWebhookJob: duplicate delivery skipped', [
                'store_id'  => $this->storeId,
                'event'     => $this->event,
                'entity_id' => $externalEntityId,
                'log_id'    => $this->webhookLogId,
            ]);
            $this->markLog('processed', null);
            return;
        }

        // --- Load store ------------------------------------------------------
        $store = Store::with('workspace')->find($this->storeId);

        if ($store === null) {
            $this->markLog('failed', 'Store not found');
            Log::error('ProcessWebhookJob: store not found', ['store_id' => $this->storeId]);
            return;
        }

        // --- Process event ---------------------------------------------------
        try {
            match ($this->event) {
                'order.created',
                'order.updated' => $orderAction->handle(
                    $store,
                    $store->workspace->reporting_currency,
                    $this->payload,
                ),
                'order.deleted'   => $this->handleOrderDeleted($externalEntityId),
                'product.updated' => $productAction->handle($store, $this->payload),
                default           => throw new \InvalidArgumentException("Unsupported webhook event: {$this->event}"),
            };

            Store::withoutGlobalScopes()
                ->where('id', $this->storeId)
                ->update(['last_synced_at' => now()]);

            $this->stampWebhookDelivery();

            $this->markLog('processed', null);
        } catch (\Throwable $e) {
            Log::error('ProcessWebhookJob: processing failed', [
                'store_id'  => $this->storeId,
                'event'     => $this->event,
                'entity_id' => $externalEntityId,
                'log_id'    => $this->webhookLogId,
                'error'     => $e->getMessage(),
            ]);

            $this->markLog('failed', mb_substr($e->getMessage(), 0, 500));

            throw $e; // rethrow so Horizon retries
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check if this event+entity_id combination was already successfully processed
     * within the last 24 hours (per spec deduplication window).
     */
    private function isDuplicate(string $externalEntityId): bool
    {
        return WebhookLog::where('store_id', $this->storeId)
            ->where('event', $this->event)
            ->whereRaw("payload->>'id' = ?", [$externalEntityId])
            ->where('status', 'processed')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
    }

    /**
     * Handle order.deleted: soft-cancel the order row, never hard-delete.
     */
    private function handleOrderDeleted(string $externalEntityId): void
    {
        if ($externalEntityId === '') {
            return;
        }

        // WorkspaceContext is set, so the scope filters to the correct workspace.
        Order::where('store_id', $this->storeId)
            ->where('external_id', $externalEntityId)
            ->update([
                'status'     => 'cancelled',
                'updated_at' => now()->toDateTimeString(),
            ]);
    }

    /**
     * Stamp store_webhooks.last_successful_delivery_at for the topic that just fired.
     * Used by PollStoreOrdersJob to determine whether webhooks are still arriving
     * before falling back to the API poll.
     *
     * The WC event "order.created" maps to the "order.created" topic row, etc.
     * Uses DB::table to bypass WorkspaceScope — scoped by store_id instead.
     */
    private function stampWebhookDelivery(): void
    {
        try {
            DB::table('store_webhooks')
                ->where('store_id', $this->storeId)
                ->where('topic', $this->event)
                ->whereNull('deleted_at')
                ->update(['last_successful_delivery_at' => now()->toDateTimeString()]);
        } catch (\Throwable $e) {
            Log::warning('ProcessWebhookJob: could not stamp last_successful_delivery_at', [
                'store_id' => $this->storeId,
                'event'    => $this->event,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update the WebhookLog row. Uses DB::table() to bypass WorkspaceScope
     * (the log was created before WorkspaceContext existed in this lifecycle).
     */
    private function markLog(string $status, ?string $errorMessage): void
    {
        try {
            DB::table('webhook_logs')
                ->where('id', $this->webhookLogId)
                ->update([
                    'status'        => $status,
                    'error_message' => $errorMessage,
                    'processed_at'  => $status === 'processed' ? now()->toDateTimeString() : null,
                    'updated_at'    => now()->toDateTimeString(),
                ]);
        } catch (\Throwable $e) {
            Log::error('ProcessWebhookJob: failed to update webhook_log', [
                'log_id' => $this->webhookLogId,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
