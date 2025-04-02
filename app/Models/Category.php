<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\TenantScope;

class Category extends Model
{
    protected $fillable = [
        'category',
        'location_id',
        // 'space_id',
        'tenant_id',
      ];

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
