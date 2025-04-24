<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;#
use \Illuminate\Database\Eloquent\Factories\HasFactory;

class Space extends Model
{


    protected $table = 'spaces';

    protected $casts = [
        'space_fee' => 'decimal:2',
        'min_space_discount_time' => 'integer',
        'space_discount' => 'decimal:2',
        'deleted' => 'boolean',
    ];

    protected $attributes = [
        'deleted' => 'no',
    ];
    protected $hidden = [
        'created_by_user_id',
        'deleted_by_user_id',
        'deleted_at',
    ];


  protected $fillable = [
        'space_name',
        'space_number',
        'space_fee',
        'min_space_discount_time',
        'space_discount',
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
    public function location(){
        return $this->belongsTo(Location::class, 'loactaion_id');
    }
}
