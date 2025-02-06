<?php

namespace App\Models;
use App\Models\Scopes\TenantScope;

use Illuminate\Database\Eloquent\Model;

class Floor extends Model
{
    protected $fillable = [
        'name',
        'tenant_id',
      ];

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
