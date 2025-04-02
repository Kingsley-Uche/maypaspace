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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function taxes()
    {
        return $this->hasMany(TaxNumber::class, 'team_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'team_users');
    }


    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
