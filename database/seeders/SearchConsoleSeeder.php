<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GscDailyStat;
use App\Models\GscPage;
use App\Models\GscQuery;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class SearchConsoleSeeder extends Seeder
{
    private const QUERIES_DE = [
        'wireless headphones', 'noise cancelling headphones', 'ergonomic office chair',
        'standing desk converter', 'mechanische tastatur', '4k monitor usb-c',
        'laptop ständer', 'schreibtischlampe usb', 'webcam 1080p', 'portable ssd',
        'dual monitor arm', 'wireless charger', 'bluetooth maus', 'mesh wlan',
        'usb hub', 'kopfhörer online kaufen', 'bester bürostuhl', 'home office einrichten',
        'gaming tastatur günstig', 'monitor arm verstellbar', 'demo store kopfhörer',
        'noise cancelling test', 'büro zubehör', 'homeoffice equipment',
        'demo store bewertung',
    ];

    private const QUERIES_UK = [
        'wireless headphones uk', 'noise cancelling earbuds', 'office chair uk',
        'standing desk uk', 'mechanical keyboard uk', '4k monitor buy',
        'laptop stand uk', 'desk lamp usb charging', 'webcam hd', 'portable ssd 1tb',
        'monitor arm uk', 'wireless charger fast', 'trackball mouse', 'mesh wifi uk',
        'usb hub 3.0', 'best headphones 2025', 'ergonomic chair review', 'wfh setup',
        'gaming keyboard budget', 'adjustable monitor stand', 'uk lifestyle store review',
        'best noise cancelling 2025', 'home office accessories', 'usb-c monitor',
        'bluetooth headphones review',
    ];

    private const QUERIES_US = [
        'wireless headphones usa', 'best noise cancelling headphones', 'ergonomic office chair',
        'standing desk riser', 'mechanical keyboard tenkeyless', 'usb c monitor 27 inch',
        'aluminum laptop stand', 'led desk lamp usb', '1080p webcam', '1tb portable ssd',
        'dual monitor arm mount', 'fast wireless charger', 'bluetooth trackball', 'whole home wifi',
        'usb 3.0 hub', 'headphones review 2025', 'best desk chair amazon', 'home office setup ideas',
        'budget mechanical keyboard', 'monitor arm adjustable', 'us gadgets store coupon',
        'noise cancelling earbuds review', 'office desk accessories', 'wfh gadgets',
        'tech accessories online',
    ];

    private const PAGES_DE = [
        '/'                               => ['clicks' => 45, 'impr' => 1200],
        '/shop'                           => ['clicks' => 38, 'impr' => 980],
        '/product/wireless-headphones'    => ['clicks' => 28, 'impr' => 720],
        '/product/ergonomic-chair'        => ['clicks' => 22, 'impr' => 590],
        '/product/standing-desk'          => ['clicks' => 18, 'impr' => 480],
        '/product/mechanical-keyboard'    => ['clicks' => 16, 'impr' => 420],
        '/product/4k-monitor'             => ['clicks' => 14, 'impr' => 380],
        '/blog/home-office-setup'         => ['clicks' => 31, 'impr' => 890],
        '/blog/best-headphones-2025'      => ['clicks' => 24, 'impr' => 650],
        '/about'                          => ['clicks' => 8,  'impr' => 210],
        '/contact'                        => ['clicks' => 5,  'impr' => 140],
        '/cart'                           => ['clicks' => 12, 'impr' => 95],
    ];

    private const PAGES_UK = [
        '/'                               => ['clicks' => 32, 'impr' => 880],
        '/shop'                           => ['clicks' => 27, 'impr' => 720],
        '/product/wireless-headphones'    => ['clicks' => 20, 'impr' => 540],
        '/product/ergonomic-chair'        => ['clicks' => 16, 'impr' => 430],
        '/product/mechanical-keyboard'    => ['clicks' => 14, 'impr' => 370],
        '/product/4k-monitor'             => ['clicks' => 11, 'impr' => 290],
        '/blog/wfh-setup-guide'           => ['clicks' => 22, 'impr' => 610],
        '/blog/best-headphones-uk'        => ['clicks' => 18, 'impr' => 490],
        '/about'                          => ['clicks' => 6,  'impr' => 160],
        '/contact'                        => ['clicks' => 4,  'impr' => 110],
    ];

    private const PAGES_US = [
        '/'                               => ['clicks' => 28, 'impr' => 750],
        '/shop'                           => ['clicks' => 22, 'impr' => 600],
        '/product/wireless-headphones'    => ['clicks' => 17, 'impr' => 460],
        '/product/portable-ssd'           => ['clicks' => 15, 'impr' => 400],
        '/product/mechanical-keyboard'    => ['clicks' => 12, 'impr' => 330],
        '/product/webcam'                 => ['clicks' => 10, 'impr' => 280],
        '/blog/best-home-office-gear'     => ['clicks' => 19, 'impr' => 530],
        '/blog/top-gadgets-2025'          => ['clicks' => 14, 'impr' => 390],
        '/about'                          => ['clicks' => 5,  'impr' => 140],
        '/contact'                        => ['clicks' => 3,  'impr' => 90],
    ];

    public function run(): void
    {
        $workspace = Workspace::where('slug', 'demo-store')->first();
        $stores    = Store::where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->get()
            ->keyBy('currency');

        $deStore = $stores['EUR'] ?? null;
        $gbStore = $stores['GBP'] ?? null;
        $usStore = $stores['USD'] ?? null;

        // ── Property 1: DE Flagship Store ─────────────────────────────────────
        if ($deStore) {
            $p1 = SearchConsoleProperty::create([
                'workspace_id'            => $workspace->id,
                'store_id'                => $deStore->id,
                'property_url'            => 'https://demo-de.dev.localhost/',
                'access_token_encrypted'  => Crypt::encryptString('gsc_demo_access_token_de'),
                'refresh_token_encrypted' => Crypt::encryptString('gsc_demo_refresh_token_de'),
                'token_expires_at'        => now()->addHour(),
                'status'                  => 'active',
                'last_synced_at'          => now()->subHours(4),
            ]);

            $this->seedPropertyData(
                $p1->id, $workspace->id,
                'https://demo-de.dev.localhost',
                self::QUERIES_DE, self::PAGES_DE,
                ['clicks' => [180, 340], 'multiplier' => [28, 42]],
            );
        }

        // ── Property 2: UK Lifestyle Store ────────────────────────────────────
        if ($gbStore) {
            $p2 = SearchConsoleProperty::create([
                'workspace_id'            => $workspace->id,
                'store_id'                => $gbStore->id,
                'property_url'            => 'https://demo-uk.dev.localhost/',
                'access_token_encrypted'  => Crypt::encryptString('gsc_demo_access_token_uk'),
                'refresh_token_encrypted' => Crypt::encryptString('gsc_demo_refresh_token_uk'),
                'token_expires_at'        => now()->addHour(),
                'status'                  => 'active',
                'last_synced_at'          => now()->subHours(5),
            ]);

            $this->seedPropertyData(
                $p2->id, $workspace->id,
                'https://demo-uk.dev.localhost',
                self::QUERIES_UK, self::PAGES_UK,
                ['clicks' => [120, 240], 'multiplier' => [22, 35]],
            );
        }

        // ── Property 3: US Gadgets Store ──────────────────────────────────────
        if ($usStore) {
            $p3 = SearchConsoleProperty::create([
                'workspace_id'            => $workspace->id,
                'store_id'                => $usStore->id,
                'property_url'            => 'https://demo-us.dev.localhost/',
                'access_token_encrypted'  => Crypt::encryptString('gsc_demo_access_token_us'),
                'refresh_token_encrypted' => Crypt::encryptString('gsc_demo_refresh_token_us'),
                'token_expires_at'        => now()->addHour(),
                'status'                  => 'active',
                'last_synced_at'          => now()->subHours(6),
            ]);

            $this->seedPropertyData(
                $p3->id, $workspace->id,
                'https://demo-us.dev.localhost',
                self::QUERIES_US, self::PAGES_US,
                ['clicks' => [100, 200], 'multiplier' => [18, 30]],
            );
        }
    }

    private function seedPropertyData(
        int $propertyId,
        int $workspaceId,
        string $baseUrl,
        array $queries,
        array $pages,
        array $ranges,
    ): void {
        for ($d = 90; $d >= 3; $d--) {
            $date     = now()->subDays($d)->toDateString();
            $variance = mt_rand(80, 120) / 100;

            $clicks      = (int) (mt_rand(...$ranges['clicks']) * $variance);
            $impressions = (int) ($clicks * mt_rand(...$ranges['multiplier']));
            $ctr         = $impressions > 0 ? round($clicks / $impressions, 6) : null;
            $position    = round(mt_rand(55, 95) / 10, 2);

            GscDailyStat::create([
                'property_id'  => $propertyId,
                'workspace_id' => $workspaceId,
                'date'         => $date,
                'clicks'       => $clicks,
                'impressions'  => $impressions,
                'ctr'          => $ctr,
                'position'     => $position,
            ]);

            // Top 20 queries per day
            $shuffled = $queries;
            shuffle($shuffled);
            foreach (array_slice($shuffled, 0, 20) as $query) {
                $qClicks = max(0, (int) (($clicks / 20) * mt_rand(50, 150) / 100));
                $qImpr   = (int) ($qClicks * mt_rand(20, 60));
                GscQuery::create([
                    'property_id'  => $propertyId,
                    'workspace_id' => $workspaceId,
                    'date'         => $date,
                    'query'        => $query,
                    'clicks'       => $qClicks,
                    'impressions'  => $qImpr,
                    'ctr'          => $qImpr > 0 ? round($qClicks / $qImpr, 6) : null,
                    'position'     => round(mt_rand(10, 120) / 10, 2),
                ]);
            }

            // Pages per day
            foreach ($pages as $path => $base) {
                $pClicks = max(0, (int) ($base['clicks'] * $variance * mt_rand(70, 130) / 100));
                $pImpr   = (int) ($base['impr'] * $variance * mt_rand(80, 120) / 100);
                GscPage::create([
                    'property_id'  => $propertyId,
                    'workspace_id' => $workspaceId,
                    'date'         => $date,
                    'page'         => $baseUrl . $path,
                    'clicks'       => $pClicks,
                    'impressions'  => $pImpr,
                    'ctr'          => $pImpr > 0 ? round($pClicks / $pImpr, 6) : null,
                    'position'     => round(mt_rand(15, 80) / 10, 2),
                ]);
            }
        }
    }
}
