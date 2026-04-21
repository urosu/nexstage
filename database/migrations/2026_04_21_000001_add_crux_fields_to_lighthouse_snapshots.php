<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add CrUX field-data columns to lighthouse_snapshots.
     *
     * PSI responses include both:
     *   - loadingExperience       → URL-level CrUX (p75 from real Chrome users on this page)
     *   - originLoadingExperience → Origin-level CrUX (p75 across entire domain)
     *
     * We store the best available CrUX tier in crux_source, and the corresponding
     * p75 values for each Core Web Vital. These are distinct from the lab columns
     * (lcp_ms, cls_score, inp_ms, etc.) which come from the Lighthouse synthetic test.
     *
     * crux_source null  → neither URL nor origin CrUX data was available (INSUFFICIENT_DATA)
     * crux_source 'url' → URL-level CrUX was available and used
     * crux_source 'origin' → Only origin-level CrUX available (URL-level insufficient)
     *
     * CLS in CrUX is an integer stored as actual_cls × 100 (so 0.10 → 10).
     * We normalise it to the same decimal(6,4) format as cls_score.
     *
     * TTFB from CrUX is labelled EXPERIMENTAL by Google — included but treat with lower confidence.
     *
     * See: PLANNING.md "Performance Monitoring"
     */
    public function up(): void
    {
        Schema::table('lighthouse_snapshots', function (Blueprint $table) {
            $table->string('crux_source', 10)->nullable()->after('strategy');  // 'url' | 'origin' | null
            $table->integer('crux_lcp_p75_ms')->nullable()->after('crux_source');
            $table->integer('crux_inp_p75_ms')->nullable()->after('crux_lcp_p75_ms');
            $table->decimal('crux_cls_p75', 6, 4)->nullable()->after('crux_inp_p75_ms');
            $table->integer('crux_fcp_p75_ms')->nullable()->after('crux_cls_p75');
            $table->integer('crux_ttfb_p75_ms')->nullable()->after('crux_fcp_p75_ms');
        });
    }

    public function down(): void
    {
        Schema::table('lighthouse_snapshots', function (Blueprint $table) {
            $table->dropColumn([
                'crux_source',
                'crux_lcp_p75_ms',
                'crux_inp_p75_ms',
                'crux_cls_p75',
                'crux_fcp_p75_ms',
                'crux_ttfb_p75_ms',
            ]);
        });
    }
};
