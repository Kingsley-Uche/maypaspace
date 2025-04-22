<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Space extends Model
{
    protected $fillable = [
        'space_name',
        'space_number',
        'space_price_hourly',
        'space_price_daily',
        'space_price_weekly',
        'space_price_monthly',
        'space_price_semi_annually',
        'space_price_annually',
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
        return $this->hasMany(Spot::class, 'space_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'space_category_id');
    }

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
