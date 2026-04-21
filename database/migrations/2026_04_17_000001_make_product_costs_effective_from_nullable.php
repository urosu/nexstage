<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes effective_from nullable so users can enter a cost that applies
 * from the beginning of time (i.e. no start-date constraint).
 * NULL effective_from is treated as "always active from the earliest date".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_costs', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_costs', function (Blueprint $table) {
            $table->date('effective_from')->nullable(false)->change();
        });
    }
};
