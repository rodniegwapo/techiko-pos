<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoidLog extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [];

    protected $searchable = [
        'amount',
        'saleItem.product.name',
        'user.name',
        'approver.name',
        'amount'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class)->withTrashed();
    }
}
