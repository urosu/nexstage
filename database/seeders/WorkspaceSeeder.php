<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::where('email', 'superadmin@nexstage.dev')->first();
        $owner      = User::where('email', 'owner@nexstage.dev')->first();
        $trial      = User::where('email', 'trial@nexstage.dev')->first();
        $member     = User::where('email', 'member@nexstage.dev')->first();

        // ── Workspace 1: Full demo data, Growth plan ──────────────────────────
        // This workspace is owned by the super admin so they see the full dashboard.
        // The regular owner and member are also members here for their own testing.
        $demo = Workspace::create([
            'name'               => 'Demo Store',
            'slug'               => 'demo-store',
            'owner_id'           => $superAdmin->id,
            'reporting_currency' => 'EUR',
            'reporting_timezone' => 'Europe/Berlin',
            'trial_ends_at'      => null,
            'billing_plan'       => 'standard',
            // Integration flags reflect what StoreSeeder/AdSeeder/SearchConsoleSeeder connect below
            'has_store'          => true,
            'has_ads'            => true,
            'has_gsc'            => true,
            'has_psi'            => false,
            // Country used for holiday seeding and billing address defaults
            'country'            => 'DE',
            'timezone'           => 'Europe/Berlin',
        ]);

        WorkspaceUser::create(['workspace_id' => $demo->id, 'user_id' => $superAdmin->id, 'role' => 'owner']);
        WorkspaceUser::create(['workspace_id' => $demo->id, 'user_id' => $owner->id,      'role' => 'admin']);
        WorkspaceUser::create(['workspace_id' => $demo->id, 'user_id' => $member->id,     'role' => 'member']);

        // ── Workspace 2: Trial workspace, no data ─────────────────────────────
        // Owned by trial@. Store connected but import not started — tests
        // the trial billing gates and the "connect store → start import" flow.
        $trialWs = Workspace::create([
            'name'               => 'Trial Store',
            'slug'               => 'trial-store',
            'owner_id'           => $trial->id,
            'reporting_currency' => 'EUR',
            'reporting_timezone' => 'Europe/Berlin',
            'trial_ends_at'      => now()->addDays(10),
            'billing_plan'       => null,
        ]);

        WorkspaceUser::create(['workspace_id' => $trialWs->id, 'user_id' => $trial->id, 'role' => 'owner']);
    }
}
