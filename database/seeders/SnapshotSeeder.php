<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DailySnapshot;
use App\Models\HourlySnapshot;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SnapshotSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::where('slug', 'demo-store')->first();
        $stores    = Store::where('workspace_id', $workspace->id)->get();

        foreach ($stores as $store) {
            $this->seedDailySnapshots($workspace->id, $store->id);
            $this->seedHourlySnapshots($workspace->id, $store->id);
        }
    }

    private function seedDailySnapshots(int $workspaceId, int $storeId): void
    {
        // Group orders by date
        $orders = Order::where('workspace_id', $workspaceId)
            ->where('store_id', $storeId)
            ->whereIn('status', ['completed', 'processing'])
            ->select('id', 'total_in_reporting_currency', 'total', 'customer_email_hash', 'customer_country', 'occurred_at')
            ->get();

        $byDate = $orders->groupBy(fn ($o) => $o->occurred_at->toDateString());

        // Track first appearance per email hash for new/returning
        $seenHashes = [];

        // Build top products from order items
        $allItems = OrderItem::where('workspace_id', $workspaceId)
            ->where('store_id', $storeId)
            ->select('order_id', 'product_external_id', 'product_name', 'quantity', 'line_total')
            ->get()
            ->keyBy('order_id');

        foreach ($byDate as $date => $dayOrders) {
            $orderIds     = $dayOrders->pluck('id')->toArray();
            $revenue      = $dayOrders->sum('total_in_reporting_currency');
            $revenueNative = $dayOrders->sum('total');
            $count        = $dayOrders->count();

            // New vs returning
            $newCustomers       = 0;
            $returningCustomers = 0;
            foreach ($dayOrders as $order) {
                if ($order->customer_email_hash === null) continue;
                if (!isset($seenHashes[$order->customer_email_hash])) {
                    $seenHashes[$order->customer_email_hash] = true;
                    $newCustomers++;
                } else {
                    $returningCustomers++;
                }
            }

            // Revenue by country
            $byCountry = $dayOrders->groupBy('customer_country')
                ->map(fn ($g) => round($g->sum('total_in_reporting_currency'), 2))
                ->toArray();

            // Top products from items on this day's orders
            $dayItems = OrderItem::where('workspace_id', $workspaceId)
                ->whereIn('order_id', $orderIds)
                ->select('product_external_id', 'product_name', 'quantity', 'line_total')
                ->get()
                ->groupBy('product_external_id');

            $topProducts = $dayItems->map(function ($items, $extId) {
                return [
                    'external_id' => $extId,
                    'name'        => $items->first()->product_name,
                    'units'       => $items->sum('quantity'),
                    'revenue'     => round($items->sum('line_total'), 2),
                ];
            })->sortByDesc('revenue')->take(10)->values()->toArray();

            $totalItems = OrderItem::where('workspace_id', $workspaceId)
                ->whereIn('order_id', $orderIds)
                ->sum('quantity');

            DailySnapshot::updateOrCreate(
                ['store_id' => $storeId, 'date' => $date],
                [
                    'workspace_id'       => $workspaceId,
                    'orders_count'       => $count,
                    'revenue'            => round($revenue, 4),
                    'revenue_native'     => round($revenueNative, 4),
                    'aov'                => $count > 0 ? round($revenue / $count, 4) : null,
                    'items_sold'         => (int) $totalItems,
                    'items_per_order'    => $count > 0 ? round($totalItems / $count, 2) : null,
                    'new_customers'      => $newCustomers,
                    'returning_customers' => $returningCustomers,
                    'revenue_by_country' => $byCountry,
                    'top_products'       => $topProducts,
                ]
            );
        }
    }

    private function seedHourlySnapshots(int $workspaceId, int $storeId): void
    {
        // Last 14 days of hourly data
        $orders = Order::where('workspace_id', $workspaceId)
            ->where('store_id', $storeId)
            ->whereIn('status', ['completed', 'processing'])
            ->where('occurred_at', '>=', now()->subDays(14))
            ->select('total_in_reporting_currency', 'occurred_at')
            ->get();

        $byDateHour = $orders->groupBy(function ($o) {
            return $o->occurred_at->toDateString() . '_' . $o->occurred_at->hour;
        });

        foreach ($byDateHour as $key => $hourOrders) {
            [$date, $hour] = explode('_', $key);

            HourlySnapshot::updateOrCreate(
                ['store_id' => $storeId, 'date' => $date, 'hour' => (int) $hour],
                [
                    'workspace_id' => $workspaceId,
                    'orders_count' => $hourOrders->count(),
                    'revenue'      => round($hourOrders->sum('total_in_reporting_currency'), 4),
                ]
            );
        }
    }
}
