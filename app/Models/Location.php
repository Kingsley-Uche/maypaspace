<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\TenantScope;

class Location extends Model
{
    protected $fillable = [
        'name',
        'state',
        'address',
        'tenant_id',
      ];

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
