<?php

namespace App\Models;
use App\Models\Scopes\TenantScope;

use Illuminate\Database\Eloquent\Model;

class Floor extends Model
{
    protected $fillable = [
        'name',
        'tenant_id',
        'location_id',
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

    public function spaces()
    {
        return $this->hasMany(Space::class);
    }

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
