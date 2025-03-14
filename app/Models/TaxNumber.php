<?php

namespace App\Models;
use App\Models\Scopes\TenantScope;

use Illuminate\Database\Eloquent\Model;

class TaxNumber extends Model
{
    protected $fillable = [
        'name',
        'value',
        'team_id',
        'tenant_id',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }

}
