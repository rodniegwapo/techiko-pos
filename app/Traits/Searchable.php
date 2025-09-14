<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

trait Searchable
{
    public function scopeSearch($query, $search)
{
    $search = trim($search);
    $fields = $this->searchable ?: [];

    return $query->where(function (Builder $query) use ($search, $fields) {
        foreach ($fields as $field) {
            if (Str::contains($field, '.')) {
                $parts = explode('.', $field);
                $last = array_pop($parts); // last part is the actual column
                $query->orWhereHas(implode('.', $parts), function ($q) use ($last, $search) {
                    $q->where($last, 'like', "%$search%");
                });
            } else {
                $query->orWhere($field, 'like', "%$search%");
            }
        }
    });
}

}
