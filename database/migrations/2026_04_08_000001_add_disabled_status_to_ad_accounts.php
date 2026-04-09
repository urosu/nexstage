<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ad_accounts DROP CONSTRAINT ad_accounts_status_check');
        DB::statement("ALTER TABLE ad_accounts ADD CONSTRAINT ad_accounts_status_check CHECK (status IN ('active','error','token_expired','disconnected','disabled'))");
    }

    public function down(): void
    {
        // Revert any disabled accounts to token_expired before restoring the narrower constraint
        DB::statement("UPDATE ad_accounts SET status = 'token_expired' WHERE status = 'disabled'");
        DB::statement('ALTER TABLE ad_accounts DROP CONSTRAINT ad_accounts_status_check');
        DB::statement("ALTER TABLE ad_accounts ADD CONSTRAINT ad_accounts_status_check CHECK (status IN ('active','error','token_expired','disconnected'))");
    }
};
