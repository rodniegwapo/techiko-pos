<?php

namespace App;

use Carbon\Carbon;

class Helpers
{
    public static function getDateRange($startDate = null, $endDate = null)
    {
       logger($startDate);
       logger($endDate);
       
        return 
        
        $range = [
            Carbon::parse($startDate)
                ->startOfDay()
                ->toDateTimeString(),
            Carbon::parse($endDate)
                ->endOfDay()
                ->toDateTimeString(),
        ];

        return $range;
    }
}
