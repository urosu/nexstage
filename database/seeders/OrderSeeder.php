<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrderSeeder extends Seeder
{
    private const PRODUCTS = [
        ['id' => '101', 'name' => 'Wireless Noise-Cancelling Headphones', 'price' => 279.00],
        ['id' => '102', 'name' => 'Ergonomic Office Chair',               'price' => 449.00],
        ['id' => '103', 'name' => 'Standing Desk Converter',              'price' => 189.00],
        ['id' => '104', 'name' => 'Mechanical Keyboard TKL',              'price' => 129.00],
        ['id' => '105', 'name' => 'USB-C 4K Monitor 27"',                 'price' => 599.00],
        ['id' => '106', 'name' => 'Laptop Stand Aluminium',               'price' => 59.00],
        ['id' => '107', 'name' => 'LED Desk Lamp with USB Charging',      'price' => 49.00],
        ['id' => '108', 'name' => 'Webcam 1080p with Privacy Cover',      'price' => 89.00],
        ['id' => '109', 'name' => 'Portable SSD 1TB',                     'price' => 109.00],
        ['id' => '110', 'name' => 'Wrist Rest Gel Pad',                   'price' => 22.00],
        ['id' => '112', 'name' => 'Dual Monitor Arm',                     'price' => 149.00],
        ['id' => '113', 'name' => 'Wireless Charging Pad 15W',            'price' => 35.00],
        ['id' => '114', 'name' => 'Bluetooth Trackball Mouse',            'price' => 99.00],
        ['id' => '116', 'name' => 'Mesh Wi-Fi System (3-pack)',           'price' => 249.00],
        ['id' => '118', 'name' => 'Noise-Cancelling Earbuds TWS',         'price' => 149.00],
        ['id' => '120', 'name' => 'USB Hub 7-Port USB 3.0',               'price' => 39.00],
    ];

    private const COUNTRIES = [
        'DE' => 0.42, 'AT' => 0.14, 'CH' => 0.10,
        'PL' => 0.08, 'NL' => 0.07, 'FR' => 0.06,
        'GB' => 0.05, 'US' => 0.04, 'IT' => 0.04,
    ];

    private const STATUSES = [
        'completed'  => 0.78,
        'processing' => 0.10,
        'refunded'   => 0.07,
        'cancelled'  => 0.05,
    ];

    // Must match campaign names in AdSeeder exactly (comparison is case-insensitive)
    private const FACEBOOK_CAMPAIGNS = [
        'Brand Awareness — DE/AT/CH',
        'Retargeting — All Visitors',
        'Lookalike — Purchasers',
        'UK — Prospecting Cold Traffic',
        'UK/US — Dynamic Product Ads',
    ];

    private const GOOGLE_CAMPAIGNS = [
        'Search — Brand Keywords',
        'Search — Generic Products',
        'Performance Max',
        'Shopping — All Products',
        'Search — Brand UK',
    ];

    private const EMAILS = [
        'alice@example.com', 'bob@example.com', 'charlie@example.com',
        'diana@example.com', 'edward@example.com', 'fiona@example.com',
        'george@example.com', 'hannah@example.com', 'ivan@example.com',
        'julia@example.com', 'kevin@example.com', 'lisa@example.com',
        'mike@example.com', 'nina@example.com', 'oscar@example.com',
        'paula@example.com', 'quinn@example.com', 'rachel@example.com',
        'steve@example.com', 'tina@example.com', 'uma@example.com',
        'victor@example.com', 'wendy@example.com', 'xander@example.com',
        'yara@example.com', 'zach@example.com', 'anna@test.de',
        'bernd@test.at', 'chris@mail.ch', 'dora@shop.pl',
        'erik@email.nl', 'franz@web.de', 'greta@post.at',
        'hans@gmx.de', 'iris@t-online.de', 'jan@wp.pl',
        'karin@icloud.com', 'lars@outlook.com', 'maria@gmail.com',
        'nils@yahoo.com', 'olga@hotmail.com', 'peter@proton.me',
    ];

    // Approximate EUR rates used for total_in_reporting_currency conversion.
    // Real conversion uses the fx_rates table; this is fine for seed data.
    private const EUR_RATES = ['EUR' => 1.0, 'GBP' => 0.86, 'USD' => 1.08];

    public function run(): void
    {
        $workspace  = Workspace::where('slug', 'demo-store')->first();
        $stores     = Store::where('workspace_id', $workspace->id)->get();
        $externalId = 10001;

        foreach ($stores as $store) {
            $orderNumber = 1001;
            $eurRate     = self::EUR_RATES[$store->currency] ?? 1.0;

            for ($daysAgo = 90; $daysAgo >= 0; $daysAgo--) {
                $baseDate    = now()->subDays($daysAgo);
                $ordersToday = rand(3, 8);

                if (in_array((int) $baseDate->format('N'), [6, 7])) {
                    $ordersToday = rand(5, 12);
                }

                for ($o = 0; $o < $ordersToday; $o++) {
                    $occurred = $baseDate->copy()->addHours(rand(8, 22))->addMinutes(rand(0, 59));
                    $status   = $this->weighted(self::STATUSES);
                    $country  = $this->weighted(self::COUNTRIES);
                    $email    = self::EMAILS[array_rand(self::EMAILS)];

                    $items    = $this->pickItems(rand(1, 2));
                    $subtotal = array_sum(array_column($items, 'line_total'));
                    $shipping = $subtotal > 100 ? 0.00 : 6.90;
                    $discount = mt_rand(0, 100) < 15 ? round($subtotal * 0.10, 2) : 0.00;
                    $tax      = round(($subtotal - $discount + $shipping) * 0.19, 2);
                    $total    = round($subtotal - $discount + $shipping + $tax, 2);

                    // Convert to EUR for reporting (case 3 from spec: reporting=EUR, order≠EUR)
                    $totalEur = $store->currency === 'EUR'
                        ? $total
                        : round($total / $eurRate, 4);

                    $utmSource   = $this->randomUtmSource();
                    $utmCampaign = match (true) {
                        in_array($utmSource, ['facebook', 'instagram']) => self::FACEBOOK_CAMPAIGNS[array_rand(self::FACEBOOK_CAMPAIGNS)],
                        $utmSource === 'google'                          => self::GOOGLE_CAMPAIGNS[array_rand(self::GOOGLE_CAMPAIGNS)],
                        default                                          => null,
                    };

                    $order = Order::create([
                        'workspace_id'                => $workspace->id,
                        'store_id'                    => $store->id,
                        'external_id'                 => (string) $externalId,
                        'external_number'             => (string) $orderNumber,
                        'status'                      => $status,
                        'currency'                    => $store->currency,
                        'total'                       => $total,
                        'subtotal'                    => $subtotal,
                        'tax'                         => $tax,
                        'shipping'                    => $shipping,
                        'discount'                    => $discount,
                        'total_in_reporting_currency' => $totalEur,
                        'customer_email_hash'         => hash('sha256', strtolower(trim($email))),
                        'customer_country'            => $country,
                        'utm_source'                  => $utmSource,
                        'utm_medium'                  => $this->randomUtmMedium(),
                        'utm_campaign'                => $utmCampaign,
                        'occurred_at'                 => $occurred,
                        'synced_at'                   => $occurred->addMinutes(rand(1, 5)),
                    ]);

                    foreach ($items as $item) {
                        OrderItem::create([
                            'order_id'            => $order->id,
                            'workspace_id'        => $workspace->id,
                            'store_id'            => $store->id,
                            'product_external_id' => $item['product_id'],
                            'product_name'        => $item['name'],
                            'variant_name'        => null,
                            'sku'                 => null,
                            'quantity'            => $item['qty'],
                            'unit_price'          => $item['price'],
                            'line_total'          => $item['line_total'],
                        ]);
                    }

                    $externalId++;
                    $orderNumber++;
                }
            }
        }
    }

    private function pickItems(int $count): array
    {
        $products = self::PRODUCTS;
        shuffle($products);
        $selected = array_slice($products, 0, $count);

        return array_map(function (array $p) {
            $qty = rand(1, 2);
            return [
                'product_id' => $p['id'],
                'name'       => $p['name'],
                'price'      => $p['price'],
                'qty'        => $qty,
                'line_total' => round($p['price'] * $qty, 2),
            ];
        }, $selected);
    }

    private function weighted(array $weights): string
    {
        $rand = mt_rand(1, 10000) / 10000;
        $cumulative = 0.0;
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return $key;
            }
        }
        return array_key_first($weights);
    }

    private function randomUtmSource(): ?string
    {
        $sources = [null, null, 'google', 'facebook', 'instagram', 'newsletter', 'bing', 'direct'];
        return $sources[array_rand($sources)];
    }

    private function randomUtmMedium(): ?string
    {
        $mediums = [null, null, 'cpc', 'social', 'email', 'organic', 'referral'];
        return $mediums[array_rand($mediums)];
    }
}
