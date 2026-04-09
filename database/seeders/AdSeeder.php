<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdInsight;
use App\Models\Adset;
use App\Models\Campaign;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class AdSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::where('slug', 'demo-store')->first();

        $this->seedFacebook($workspace->id);
        $this->seedGoogle($workspace->id);
    }

    private function seedFacebook(int $workspaceId): void
    {
        // ── Account 1: DE/AT/CH brand account (EUR) ───────────────────────────
        $fb1 = AdAccount::create([
            'workspace_id'           => $workspaceId,
            'platform'               => 'facebook',
            'external_id'            => 'act_123456789',
            'name'                   => 'FB — DACH Brand Account',
            'currency'               => 'EUR',
            'access_token_encrypted' => Crypt::encryptString('fb_demo_access_token_1'),
            'token_expires_at'       => now()->addDays(60),
            'status'                 => 'active',
            'last_synced_at'         => now()->subMinutes(90),
        ]);

        $fb1Campaigns = [
            ['ext' => 'fb_cmp_001', 'name' => 'Brand Awareness — DE/AT/CH', 'budget_factor' => 0.3],
            ['ext' => 'fb_cmp_002', 'name' => 'Retargeting — All Visitors',  'budget_factor' => 0.4],
            ['ext' => 'fb_cmp_003', 'name' => 'Lookalike — Purchasers',      'budget_factor' => 0.3],
        ];

        foreach ($fb1Campaigns as $cmpData) {
            $this->seedFbCampaign($workspaceId, $fb1->id, $cmpData, 'EUR');
        }

        // ── Account 2: UK/US performance account (GBP) ────────────────────────
        $fb2 = AdAccount::create([
            'workspace_id'           => $workspaceId,
            'platform'               => 'facebook',
            'external_id'            => 'act_987654321',
            'name'                   => 'FB — UK/US Performance Account',
            'currency'               => 'GBP',
            'access_token_encrypted' => Crypt::encryptString('fb_demo_access_token_2'),
            'token_expires_at'       => now()->addDays(45),
            'status'                 => 'active',
            'last_synced_at'         => now()->subMinutes(120),
        ]);

        $fb2Campaigns = [
            ['ext' => 'fb_cmp_uk_001', 'name' => 'UK — Prospecting Cold Traffic', 'budget_factor' => 0.5],
            ['ext' => 'fb_cmp_uk_002', 'name' => 'UK/US — Dynamic Product Ads',   'budget_factor' => 0.5],
        ];

        foreach ($fb2Campaigns as $cmpData) {
            $this->seedFbCampaign($workspaceId, $fb2->id, $cmpData, 'GBP');
        }
    }

    private function seedFbCampaign(int $workspaceId, int $accountId, array $cmpData, string $currency): void
    {
        // EUR rate for reporting currency conversion (approx)
        $toEur = match ($currency) {
            'GBP' => 1 / 0.86,
            'USD' => 1 / 1.08,
            default => 1.0,
        };

        $cmp = Campaign::create([
            'workspace_id'  => $workspaceId,
            'ad_account_id' => $accountId,
            'external_id'   => $cmpData['ext'],
            'name'          => $cmpData['name'],
            'status'        => 'ACTIVE',
            'objective'     => 'CONVERSIONS',
        ]);

        $adset = Adset::create([
            'workspace_id' => $workspaceId,
            'campaign_id'  => $cmp->id,
            'external_id'  => $cmpData['ext'] . '_adset_1',
            'name'         => $cmpData['name'] . ' — Main Adset',
            'status'       => 'ACTIVE',
        ]);

        $ad = Ad::create([
            'workspace_id'    => $workspaceId,
            'adset_id'        => $adset->id,
            'external_id'     => $cmpData['ext'] . '_ad_1',
            'name'            => $cmpData['name'] . ' — Primary Creative',
            'status'          => 'ACTIVE',
            'destination_url' => 'https://demo-de.dev.localhost',
        ]);

        for ($d = 90; $d >= 0; $d--) {
            $date  = now()->subDays($d)->toDateString();
            $spend = round(($cmpData['budget_factor'] * mt_rand(120, 280)) / 10, 2);
            $impr  = (int) ($spend * mt_rand(180, 320));
            $clicks = (int) ($impr * (mt_rand(8, 22) / 1000));
            $roas  = round(mt_rand(18, 55) / 10, 2);
            $spendEur = round($spend * $toEur, 4);

            AdInsight::create([
                'workspace_id'                => $workspaceId,
                'ad_account_id'               => $accountId,
                'level'                       => 'campaign',
                'campaign_id'                 => $cmp->id,
                'adset_id'                    => null,
                'ad_id'                       => null,
                'date'                        => $date,
                'hour'                        => null,
                'spend'                       => $spend,
                'spend_in_reporting_currency' => $spendEur,
                'impressions'                 => $impr,
                'clicks'                      => $clicks,
                'reach'                       => (int) ($impr * 0.7),
                'ctr'                         => $impr > 0 ? round($clicks / $impr, 6) : null,
                'cpc'                         => $clicks > 0 ? round($spend / $clicks, 4) : null,
                'platform_roas'               => $roas,
                'currency'                    => $currency,
            ]);

            AdInsight::create([
                'workspace_id'                => $workspaceId,
                'ad_account_id'               => $accountId,
                'level'                       => 'ad',
                'campaign_id'                 => $cmp->id,
                'adset_id'                    => $adset->id,
                'ad_id'                       => $ad->id,
                'date'                        => $date,
                'hour'                        => null,
                'spend'                       => $spend,
                'spend_in_reporting_currency' => $spendEur,
                'impressions'                 => $impr,
                'clicks'                      => $clicks,
                'reach'                       => (int) ($impr * 0.7),
                'ctr'                         => $impr > 0 ? round($clicks / $impr, 6) : null,
                'cpc'                         => $clicks > 0 ? round($spend / $clicks, 4) : null,
                'platform_roas'               => $roas,
                'currency'                    => $currency,
            ]);
        }
    }

    private function seedGoogle(int $workspaceId): void
    {
        // ── Account 1: DE Search (EUR) ────────────────────────────────────────
        $g1 = AdAccount::create([
            'workspace_id'            => $workspaceId,
            'platform'                => 'google',
            'external_id'             => '123-456-7890',
            'name'                    => 'Google Ads — DE Search',
            'currency'                => 'EUR',
            'access_token_encrypted'  => Crypt::encryptString('google_demo_access_token_1'),
            'refresh_token_encrypted' => Crypt::encryptString('google_demo_refresh_token_1'),
            'token_expires_at'        => now()->addHour(),
            'status'                  => 'active',
            'last_synced_at'          => now()->subMinutes(60),
        ]);

        $g1Campaigns = [
            ['ext' => 'g_cmp_001', 'name' => 'Search — Brand Keywords',   'budget_factor' => 0.25],
            ['ext' => 'g_cmp_002', 'name' => 'Search — Generic Products', 'budget_factor' => 0.45],
            ['ext' => 'g_cmp_003', 'name' => 'Performance Max',           'budget_factor' => 0.30],
        ];

        foreach ($g1Campaigns as $cmpData) {
            $this->seedGoogleCampaign($workspaceId, $g1->id, $cmpData, 'EUR');
        }

        // ── Account 2: UK Shopping (GBP) ─────────────────────────────────────
        $g2 = AdAccount::create([
            'workspace_id'            => $workspaceId,
            'platform'                => 'google',
            'external_id'             => '456-789-0123',
            'name'                    => 'Google Ads — UK Shopping',
            'currency'                => 'GBP',
            'access_token_encrypted'  => Crypt::encryptString('google_demo_access_token_2'),
            'refresh_token_encrypted' => Crypt::encryptString('google_demo_refresh_token_2'),
            'token_expires_at'        => now()->addHour(),
            'status'                  => 'active',
            'last_synced_at'          => now()->subMinutes(75),
        ]);

        $g2Campaigns = [
            ['ext' => 'g_uk_cmp_001', 'name' => 'Shopping — All Products',     'budget_factor' => 0.6],
            ['ext' => 'g_uk_cmp_002', 'name' => 'Search — Brand UK',           'budget_factor' => 0.4],
        ];

        foreach ($g2Campaigns as $cmpData) {
            $this->seedGoogleCampaign($workspaceId, $g2->id, $cmpData, 'GBP');
        }
    }

    private function seedGoogleCampaign(int $workspaceId, int $accountId, array $cmpData, string $currency): void
    {
        $toEur = match ($currency) {
            'GBP' => 1 / 0.86,
            'USD' => 1 / 1.08,
            default => 1.0,
        };

        $cmp = Campaign::create([
            'workspace_id'  => $workspaceId,
            'ad_account_id' => $accountId,
            'external_id'   => $cmpData['ext'],
            'name'          => $cmpData['name'],
            'status'        => 'ENABLED',
            'objective'     => null,
        ]);

        $adset = Adset::create([
            'workspace_id' => $workspaceId,
            'campaign_id'  => $cmp->id,
            'external_id'  => $cmpData['ext'] . '_adgrp_1',
            'name'         => $cmpData['name'] . ' — Ad Group 1',
            'status'       => 'ENABLED',
        ]);

        $ad = Ad::create([
            'workspace_id'    => $workspaceId,
            'adset_id'        => $adset->id,
            'external_id'     => $cmpData['ext'] . '_ad_1',
            'name'            => $cmpData['name'] . ' — RSA 1',
            'status'          => 'ENABLED',
            'destination_url' => 'https://demo-de.dev.localhost',
        ]);

        for ($d = 90; $d >= 0; $d--) {
            $date   = now()->subDays($d)->toDateString();
            $spend  = round(($cmpData['budget_factor'] * mt_rand(80, 200)) / 10, 2);
            $impr   = (int) ($spend * mt_rand(300, 600));
            $clicks = (int) ($impr * (mt_rand(20, 60) / 1000));
            $spendEur = round($spend * $toEur, 4);

            AdInsight::create([
                'workspace_id'                => $workspaceId,
                'ad_account_id'               => $accountId,
                'level'                       => 'campaign',
                'campaign_id'                 => $cmp->id,
                'adset_id'                    => null,
                'ad_id'                       => null,
                'date'                        => $date,
                'hour'                        => null,
                'spend'                       => $spend,
                'spend_in_reporting_currency' => $spendEur,
                'impressions'                 => $impr,
                'clicks'                      => $clicks,
                'reach'                       => null,
                'ctr'                         => $impr > 0 ? round($clicks / $impr, 6) : null,
                'cpc'                         => $clicks > 0 ? round($spend / $clicks, 4) : null,
                'platform_roas'               => null,
                'currency'                    => $currency,
            ]);

            AdInsight::create([
                'workspace_id'                => $workspaceId,
                'ad_account_id'               => $accountId,
                'level'                       => 'ad',
                'campaign_id'                 => $cmp->id,
                'adset_id'                    => $adset->id,
                'ad_id'                       => $ad->id,
                'date'                        => $date,
                'hour'                        => null,
                'spend'                       => $spend,
                'spend_in_reporting_currency' => $spendEur,
                'impressions'                 => $impr,
                'clicks'                      => $clicks,
                'reach'                       => null,
                'ctr'                         => $impr > 0 ? round($clicks / $impr, 6) : null,
                'cpc'                         => $clicks > 0 ? round($spend / $clicks, 4) : null,
                'platform_roas'               => null,
                'currency'                    => $currency,
            ]);
        }
    }
}
