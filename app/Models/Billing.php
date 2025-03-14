<?php

namespace App\Models;
use App\Models\Scopes\TenantScope;

use Illuminate\Database\Eloquent\Model;

class Billing extends Model
{
    protected $fillable = [
        'country',
        'state',
        'street_address',
        'city',
        'postal_code',
        'additional_recipients',
        'team_id',
        'tenant_id',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
