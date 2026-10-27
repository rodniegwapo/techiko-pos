<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One uploaded CSV of past (backdated) sales. The sales it created point back here through sales.import_id. */
class SaleImport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'import_id');
    }
}
