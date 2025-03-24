<?php

namespace App\Models;
use App\Models\Scopes\TenantScope;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $fillable = [
        'company',
        'department',
        'business_number',
        'external_id',
        'description',
        'created_by_user_id',
        'tenant_id',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
