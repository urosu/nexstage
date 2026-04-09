<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Listens to Cashier's WebhookReceived event and keeps workspaces.billing_plan
 * in sync with Stripe subscription state.
 *
 * Handled events:
 *   customer.subscription.created  → set billing_plan from price ID lookup
 *   customer.subscription.updated  → update billing_plan
 *   customer.subscription.deleted  → set billing_plan = null
 */
class SyncBillingPlanFromStripe
{
    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $type    = $payload['type'] ?? '';

        if (! str_starts_with($type, 'customer.subscription.')) {
            return;
        }

        $subscription = $payload['data']['object'] ?? [];
        $stripeId     = $subscription['customer'] ?? null;

        if ($stripeId === null) {
            Log::warning('SyncBillingPlanFromStripe: missing customer ID in payload', ['type' => $type]);
            return;
        }

        $workspace = Workspace::withoutGlobalScopes()
            ->where('stripe_id', $stripeId)
            ->select(['id', 'billing_plan'])
            ->first();

        if ($workspace === null) {
            // Not our workspace — Cashier may receive events for test/other customers.
            return;
        }

        match ($type) {
            'customer.subscription.created' => $this->handleCreated($workspace, $subscription),
            'customer.subscription.updated' => $this->handleUpdated($workspace, $subscription),
            'customer.subscription.deleted' => $this->handleDeleted($workspace),
            default                          => null,
        };
    }

    private function handleCreated(Workspace $workspace, array $subscription): void
    {
        $plan = $this->resolvePlanFromSubscription($subscription);

        $workspace->billing_plan = $plan;
        $workspace->save();

        Log::info('SyncBillingPlanFromStripe: plan set on subscription created', [
            'workspace_id' => $workspace->id,
            'billing_plan' => $plan,
        ]);
    }

    private function handleUpdated(Workspace $workspace, array $subscription): void
    {
        $plan = $this->resolvePlanFromSubscription($subscription);

        $workspace->billing_plan = $plan;
        $workspace->save();

        Log::info('SyncBillingPlanFromStripe: plan updated', [
            'workspace_id' => $workspace->id,
            'billing_plan' => $plan,
        ]);
    }

    private function handleDeleted(Workspace $workspace): void
    {
        $workspace->billing_plan = null;
        $workspace->save();

        Log::info('SyncBillingPlanFromStripe: billing_plan cleared on subscription deleted', [
            'workspace_id' => $workspace->id,
        ]);
    }

    /**
     * Resolve billing_plan string from a Stripe subscription object.
     *
     * Looks up the active price ID against config/billing.php flat_plans and
     * percentage_plan. Returns null if no match (treated as no active plan).
     */
    private function resolvePlanFromSubscription(array $subscription): ?string
    {
        // Extract the price ID from the first subscription item.
        $items   = $subscription['items']['data'] ?? [];
        $priceId = $items[0]['price']['id'] ?? ($subscription['plan']['id'] ?? null);

        if ($priceId === null) {
            return null;
        }

        // Check flat plans.
        /** @var array<string, array{price_id_monthly: string|null, price_id_annual: string|null, revenue_limit: int}> $flatPlans */
        $flatPlans = config('billing.flat_plans', []);

        foreach ($flatPlans as $planName => $config) {
            if ($priceId === $config['price_id_monthly'] || $priceId === $config['price_id_annual']) {
                return $planName;
            }
        }

        // Check percentage plan.
        $percentagePriceId = config('billing.percentage_plan.price_id');

        if ($percentagePriceId !== null && $priceId === $percentagePriceId) {
            return 'percentage';
        }

        Log::warning('SyncBillingPlanFromStripe: unrecognised price ID', ['price_id' => $priceId]);

        return null;
    }
}
