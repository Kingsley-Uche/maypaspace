<?php

namespace App\Models;
use App\Models\Scopes\TenantScope;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'name',
        'description',
        'tenant_id',
        'publish'
    ];

    public function reads()
    {
        return $this->hasMany(\App\Models\NotificationRead::class);
    }


    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
