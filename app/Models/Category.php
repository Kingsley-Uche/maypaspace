<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\TenantScope;

class Category extends Model
{
    protected $fillable = [
        'category',
        'location_id',
        'tenant_id',
        'booking_type',
        'min_duration',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }

    // Optional: Relationships
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
