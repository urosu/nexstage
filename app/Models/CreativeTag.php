<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single allowed value within a creative_tag_categories dimension.
 *
 * Written by CreativeTagSeeder; managed by the system (not per-workspace).
 * The ad_creative_tags pivot records which ads carry which tags (assigned by the
 * AI tagging job or manually by a user in a future phase).
 *
 * @see app/Models/CreativeTagCategory.php
 * @see app/Models/Ad.php
 */
class CreativeTag extends Model
{
    protected $fillable = ['category_id', 'name', 'label', 'sort_order'];

    /** @return BelongsTo<CreativeTagCategory, CreativeTag> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CreativeTagCategory::class, 'category_id');
    }

    /** @return BelongsToMany<Ad> */
    public function ads(): BelongsToMany
    {
        return $this->belongsToMany(Ad::class, 'ad_creative_tags')
            ->withPivot(['confidence', 'source', 'tagged_at'])
            ->withTimestamps();
    }
}
