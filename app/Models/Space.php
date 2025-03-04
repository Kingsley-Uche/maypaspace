<?php

namespace App\Models;
use App\Models\Scopes\TenantScope;

use Illuminate\Database\Eloquent\Model;

class Space extends Model
{
    protected $fillable = [
        'space_name',
        'space_number',
        'space_fee',
        'space_category_id',
        'location_id',
        'floor_id',
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

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function spots()
    {
        return $this->hasMany(Spot::class);
    }
    
    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
