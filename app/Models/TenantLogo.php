<?php

namespace App\Models;
use App\Models\Scopes\TenantScope;

use Illuminate\Database\Eloquent\Model;

class TenantLogo extends Model
{
    protected $fillable = [
        'logo',
        'colour',
        'tenant_id',
    ];

    public function tenants()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
