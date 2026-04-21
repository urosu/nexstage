<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            // 'public' = national/work-free holidays via Yasumi
            // 'commercial' = ecommerce sale events (Black Friday, Valentine's Day, etc.)
            $table->string('type', 16)->default('public')->after('year');
            $table->string('category', 64)->nullable()->after('type'); // shopping, gifting, seasonal, cultural

            $table->index(['country_code', 'year', 'type'], 'holidays_country_year_type_index');
        });

        DB::statement("ALTER TABLE holidays ADD CONSTRAINT holidays_type_check CHECK (type IN ('public', 'commercial'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE holidays DROP CONSTRAINT IF EXISTS holidays_type_check');

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropIndex('holidays_country_year_type_index');
            $table->dropColumn(['type', 'category']);
        });
    }
};
