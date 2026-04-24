<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the eight creative analysis dimensions (asset_type, visual_format, …).
 *
 * Each category owns a fixed set of allowed slugs in creative_tags. The AI tagging
 * job constrains its output to those slugs, giving the Hit Rate × Spend Use Ratio
 * QuadrantChart consistent axes across all workspaces.
 *
 * @see Database\Seeders\CreativeTagSeeder
 * @see app/Jobs/TagCreativesWithAiJob.php
 * @see PROGRESS.md §Phase 4.1
 */
class CreativeTagCategory extends Model
{
    protected $fillable = ['name', 'label', 'sort_order'];

    /** @return HasMany<CreativeTag> */
    public function tags(): HasMany
    {
        return $this->hasMany(CreativeTag::class, 'category_id')->orderBy('sort_order');
    }
}
