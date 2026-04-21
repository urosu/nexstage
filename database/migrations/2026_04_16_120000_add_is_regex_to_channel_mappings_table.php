<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds is_regex column to channel_mappings.
 *
 * When true, utm_source_pattern is a PCRE pattern without delimiters (e.g.
 * "google\.[a-z]{2,3}"). ChannelClassifierService wraps it as /^…$/i at
 * match time. Only global seed rows (is_global=true) ever use is_regex=true;
 * workspace override rows always stay false, so the UI needs no changes.
 *
 * @see PLANNING.md section 16.4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_mappings', function (Blueprint $table) {
            $table->boolean('is_regex')->default(false)->after('is_global');
        });
    }

    public function down(): void
    {
        Schema::table('channel_mappings', function (Blueprint $table) {
            $table->dropColumn('is_regex');
        });
    }
};
