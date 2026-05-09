<?php

namespace App\Support\Wallet;

use App\Helpers;
use App\Models\Domain;
use App\Models\InventoryLocation;
use Illuminate\Http\Request;

class WalletLocationResolver
{
    public static function resolve(Request $request, Domain $domain): InventoryLocation
    {
        $location = Helpers::getActiveLocation($domain, $request->input('location_id'));

        if (! $location) {
            abort(422, 'No active location found for this domain.');
        }

        if ($location->domain !== $domain->name_slug) {
            abort(403);
        }

        return $location;
    }
}
