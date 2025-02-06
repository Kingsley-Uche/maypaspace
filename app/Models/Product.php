<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\TenantScope;

class Product extends Model
{
    protected $fillable = [
        'name',
        'location_id',
        'category_id',
        'images',
        'floor_id',
        'total_seats',
        'product_type_id',
        'tenant_id',
      ];

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
