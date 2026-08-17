<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum rows for VAT CSV export
    |--------------------------------------------------------------------------
    |
    | Prevents exporting unbounded datasets. Increase if accountants need more.
    |
    */

    'max_export_rows' => (int) env('VAT_REPORT_MAX_EXPORT_ROWS', 50000),

];
