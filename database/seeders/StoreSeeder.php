<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Store;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        // Demo workspace (superadmin / owner / member) — full data, Growth plan
        $demo = Workspace::where('slug', 'demo-store')->first();

        $demoStores = [
            [
                'name'     => 'DE Flagship Store',
                'slug'     => 'de-flagship',
                'domain'   => 'https://demo-de.dev.localhost',
                'currency' => 'EUR',
                'timezone' => 'Europe/Berlin',
            ],
            [
                'name'     => 'UK Lifestyle Store',
                'slug'     => 'uk-lifestyle',
                'domain'   => 'https://demo-uk.dev.localhost',
                'currency' => 'GBP',
                'timezone' => 'Europe/London',
            ],
            [
                'name'     => 'US Gadgets Store',
                'slug'     => 'us-gadgets',
                'domain'   => 'https://demo-us.dev.localhost',
                'currency' => 'USD',
                'timezone' => 'America/New_York',
            ],
        ];

        foreach ($demoStores as $s) {
            Store::create([
                'workspace_id'                   => $demo->id,
                'name'                           => $s['name'],
                'slug'                           => $s['slug'],
                'type'                           => 'woocommerce',
                'domain'                         => $s['domain'],
                'currency'                       => $s['currency'],
                'timezone'                       => $s['timezone'],
                'status'                         => 'active',
                'auth_key_encrypted'             => Crypt::encryptString('ck_' . strtolower($s['currency']) . '_key'),
                'auth_secret_encrypted'          => Crypt::encryptString('cs_' . strtolower($s['currency']) . '_secret'),
                'webhook_secret_encrypted'       => Crypt::encryptString('wh_' . strtolower($s['currency']) . '_secret'),
                'historical_import_status'       => 'completed',
                'historical_import_from'         => now()->subDays(90)->toDateString(),
                'historical_import_progress'     => 100,
                'historical_import_completed_at' => now()->subHours(rand(6, 48)),
                'last_synced_at'                 => now()->subMinutes(rand(10, 90)),
                'consecutive_sync_failures'      => 0,
            ]);
        }

        // Trial workspace — store connected, import not yet started
        // Tests the "start import" step and trial billing gates
        $trial = Workspace::where('slug', 'trial-store')->first();

        Store::create([
            'workspace_id'              => $trial->id,
            'name'                      => 'Trial WooCommerce Store',
            'slug'                      => 'trial-store',
            'type'                      => 'woocommerce',
            'domain'                    => 'https://trial-store.dev.localhost',
            'currency'                  => 'EUR',
            'timezone'                  => 'Europe/Berlin',
            'status'                    => 'active',
            'auth_key_encrypted'        => Crypt::encryptString('ck_trial_key'),
            'auth_secret_encrypted'     => Crypt::encryptString('cs_trial_secret'),
            'webhook_secret_encrypted'  => Crypt::encryptString('trial_webhook_secret'),
            'historical_import_status'  => null,
            'last_synced_at'            => null,
            'consecutive_sync_failures' => 0,
        ]);
    }
}
