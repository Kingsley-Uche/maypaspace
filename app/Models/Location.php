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
        'created_by_user_id',
        'deleted',
        'deleted_by_user_id',
        'deleted_at'
      ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function tenants()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
// in App\Models\Location

public function timeZone()
{
    return $this->hasOne(TimeZoneModel::class, 'location_id', 'id');
}



    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
