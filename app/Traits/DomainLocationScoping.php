<?php

namespace App\Traits;

use App\Models\InventoryLocation;
use Illuminate\Http\Request;

trait DomainLocationScoping
{
    protected $domain;
    protected $locationId;
    protected $location;

    /**
     * Initialize domain and location scoping with role-based access control
     */
    protected function initializeScoping(Request $request, $domain = null)
    {
        $user = auth()->user();
        
        if ($user) {
            // Apply role-based domain filtering
            $this->domain = $user->getEffectiveDomain($domain ? $domain->name_slug : request()->route('domain'));
            
            // Apply role-based location filtering
            $this->locationId = $user->getEffectiveLocationId($request->input('location_id'));
        } else {
            // Fallback when user is not authenticated
            $this->domain = $domain ? $domain->name_slug : request()->route('domain');
            $this->locationId = $request->input('location_id');
        }
        
        // Get the location object if location_id is provided
        if ($this->locationId) {
            $this->location = InventoryLocation::forDomain($this->domain)
                ->findOrFail($this->locationId);
        }
    }

    /**
     * Apply domain and location scoping to a query
     */
    protected function applyScoping($query, $domainColumn = 'domain', $locationColumn = 'location_id')
    {
        // Apply domain scoping
        $query->where($domainColumn, $this->domain);
        
        // Apply location scoping if location is specified
        if ($this->locationId) {
            $query->where($locationColumn, $this->locationId);
        }
        
        return $query;
    }

    /**
     * Get the current domain
     */
    protected function getDomain()
    {
        return $this->domain;
    }

    /**
     * Get the current location ID
     */
    protected function getLocationId()
    {
        return $this->locationId;
    }

    /**
     * Get the current location object
     */
    protected function getLocation()
    {
        return $this->location;
    }

    /**
     * Check if location filtering is active
     */
    protected function hasLocationFilter()
    {
        return !is_null($this->locationId);
    }
}
