<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the eight Motion-inspired creative analysis categories and their fixed tag
 * vocabularies. The AI tagging job (TagCreativesWithAiJob) constrains its output
 * to these exact slugs, so the Hit Rate × Spend Use Ratio QuadrantChart has
 * consistent, comparable axes across workspaces.
 *
 * brand_specific is intentionally seeded empty — workspaces will add their own
 * tags via UI in a future phase.
 *
 * @see app/Jobs/TagCreativesWithAiJob.php
 * @see PROGRESS.md §Phase 4.1
 */
class CreativeTagSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->toDateTimeString();

        $taxonomy = [
            [
                'name'       => 'asset_type',
                'label'      => 'Asset Type',
                'sort_order' => 1,
                'tags'       => [
                    ['name' => 'video',        'label' => 'Video',        'sort_order' => 1],
                    ['name' => 'static_image', 'label' => 'Static Image', 'sort_order' => 2],
                    ['name' => 'carousel',     'label' => 'Carousel',     'sort_order' => 3],
                    ['name' => 'story',        'label' => 'Story',        'sort_order' => 4],
                    ['name' => 'reel',         'label' => 'Reel',         'sort_order' => 5],
                ],
            ],
            [
                'name'       => 'visual_format',
                'label'      => 'Visual Format',
                'sort_order' => 2,
                'tags'       => [
                    ['name' => 'product_demo',  'label' => 'Product Demo',  'sort_order' => 1],
                    ['name' => 'lifestyle',     'label' => 'Lifestyle',     'sort_order' => 2],
                    ['name' => 'ugc',           'label' => 'UGC',           'sort_order' => 3],
                    ['name' => 'talking_head',  'label' => 'Talking Head',  'sort_order' => 4],
                    ['name' => 'animation',     'label' => 'Animation',     'sort_order' => 5],
                    ['name' => 'text_only',     'label' => 'Text Only',     'sort_order' => 6],
                ],
            ],
            [
                'name'       => 'hook_tactic',
                'label'      => 'Hook Tactic',
                'sort_order' => 3,
                'tags'       => [
                    ['name' => 'question',         'label' => 'Question',         'sort_order' => 1],
                    ['name' => 'bold_claim',       'label' => 'Bold Claim',       'sort_order' => 2],
                    ['name' => 'problem_solution', 'label' => 'Problem–Solution', 'sort_order' => 3],
                    ['name' => 'social_proof',     'label' => 'Social Proof',     'sort_order' => 4],
                    ['name' => 'before_after',     'label' => 'Before & After',   'sort_order' => 5],
                    ['name' => 'shock_surprise',   'label' => 'Shock & Surprise', 'sort_order' => 6],
                ],
            ],
            [
                'name'       => 'messaging_theme',
                'label'      => 'Messaging Theme',
                'sort_order' => 4,
                'tags'       => [
                    ['name' => 'value_discount', 'label' => 'Value & Discount', 'sort_order' => 1],
                    ['name' => 'aspiration',     'label' => 'Aspiration',       'sort_order' => 2],
                    ['name' => 'fomo',           'label' => 'FOMO',             'sort_order' => 3],
                    ['name' => 'tutorial',       'label' => 'Tutorial',         'sort_order' => 4],
                    ['name' => 'comparison',     'label' => 'Comparison',       'sort_order' => 5],
                    ['name' => 'testimonial',    'label' => 'Testimonial',      'sort_order' => 6],
                ],
            ],
            [
                'name'       => 'intended_audience',
                'label'      => 'Intended Audience',
                'sort_order' => 5,
                'tags'       => [
                    ['name' => 'new_customers', 'label' => 'New Customers', 'sort_order' => 1],
                    ['name' => 'retargeting',   'label' => 'Retargeting',   'sort_order' => 2],
                    ['name' => 'lookalike',     'label' => 'Lookalike',     'sort_order' => 3],
                    ['name' => 'broad',         'label' => 'Broad',         'sort_order' => 4],
                ],
            ],
            [
                'name'       => 'seasonality',
                'label'      => 'Seasonality',
                'sort_order' => 6,
                'tags'       => [
                    ['name' => 'evergreen',   'label' => 'Evergreen',   'sort_order' => 1],
                    ['name' => 'seasonal',    'label' => 'Seasonal',    'sort_order' => 2],
                    ['name' => 'sale_event',  'label' => 'Sale Event',  'sort_order' => 3],
                    ['name' => 'holiday',     'label' => 'Holiday',     'sort_order' => 4],
                ],
            ],
            [
                'name'       => 'offer_type',
                'label'      => 'Offer Type',
                'sort_order' => 7,
                'tags'       => [
                    ['name' => 'discount',      'label' => 'Discount',      'sort_order' => 1],
                    ['name' => 'free_shipping', 'label' => 'Free Shipping', 'sort_order' => 2],
                    ['name' => 'bundle',        'label' => 'Bundle',        'sort_order' => 3],
                    ['name' => 'subscription',  'label' => 'Subscription',  'sort_order' => 4],
                    ['name' => 'trial',         'label' => 'Trial',         'sort_order' => 5],
                    ['name' => 'no_offer',      'label' => 'No Offer',      'sort_order' => 6],
                ],
            ],
            [
                'name'       => 'brand_specific',
                'label'      => 'Brand-Specific',
                'sort_order' => 8,
                'tags'       => [], // Workspaces add their own via future UI
            ],
        ];

        foreach ($taxonomy as $cat) {
            $categoryId = DB::table('creative_tag_categories')->insertGetId([
                'name'       => $cat['name'],
                'label'      => $cat['label'],
                'sort_order' => $cat['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($cat['tags'] as $tag) {
                DB::table('creative_tags')->insert([
                    'category_id' => $categoryId,
                    'name'        => $tag['name'],
                    'label'       => $tag['label'],
                    'sort_order'  => $tag['sort_order'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }
}
