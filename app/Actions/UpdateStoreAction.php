<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Store;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateStoreAction
{
    /**
     * @param array{name: string, slug: string, timezone: string} $validated
     */
    public function handle(Store $store, array $validated): Store
    {
        $newSlug = Str::slug($validated['slug']) ?: Str::slug($validated['name']) ?: 'store';

        // Enforce uniqueness within the workspace, excluding this store
        if ($newSlug !== $store->slug) {
            $collision = Store::withoutGlobalScopes()
                ->where('workspace_id', $store->workspace_id)
                ->where('slug', $newSlug)
                ->where('id', '!=', $store->id)
                ->exists();

            if ($collision) {
                throw ValidationException::withMessages([
                    'slug' => 'This URL identifier is already in use by another store in this workspace.',
                ]);
            }
        }

        $store->update([
            'name'     => $validated['name'],
            'slug'     => $newSlug,
            'timezone' => $validated['timezone'],
        ]);

        return $store->fresh();
    }
}
