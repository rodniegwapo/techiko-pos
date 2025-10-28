<?php

namespace App\Traits;

use App\Models\Category;
use App\Models\InventoryLocation;

trait LocationCategoryScoping
{
    /**
     * Get categories that have products available at the given location
     */
    public function getCategoriesForLocation(string $domain, InventoryLocation $location)
    {
        return Category::where('domain', $domain)
            ->whereHas('products', function ($q) use ($location) {
                $q->whereHas('activeLocations', function ($lq) use ($location) {
                    $lq->where('location_id', $location->id);
                });
            });
    }

    /**
     * Get categories with product counts for the given location
     */
    public function getCategoriesWithCountsForLocation(string $domain, InventoryLocation $location)
    {
        return $this->getCategoriesForLocation($domain, $location)
            ->withCount(['products' => function ($q) use ($location) {
                $q->whereHas('activeLocations', function ($lq) use ($location) {
                    $lq->where('location_id', $location->id);
                });
            }]);
    }
}
