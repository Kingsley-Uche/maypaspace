<?php

namespace App\Models;
use App\Models\Scopes\TenantScope;

use Illuminate\Database\Eloquent\Model;

class UserType extends Model
{
    protected $fillable = [
        'user_type',
        'tenant_id',
        'created_by_user_id',
        'create_admin',
        'update_admin',
        'delete_admin',
        'view_admin',
        'create_user',
        'update_user',
        'delete_user',
        'view_user',
        'create_location',
        'update_location',
        'delete_location',
        'view_location',
        'create_floor',
        'update_floor',
        'delete_floor',
        'view_floor',
        'create_space',
        'update_space',
        'delete_space',
        'view_space',
        'create_booking',
        'update_booking',
        'delete_booking',
        'view_booking',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'user_type_id');
    }

    public function discount()
    {
        return $this->hasOne(Discount::class);
    }


    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
