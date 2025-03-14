<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['role','create_tenant','update_tenant', 'delete_tenant', 'view_tenant', 'view_tenant_income', 'create_plan'];

    public function admins()
    {
        return $this->hasMany(Admin::class, 'role_id');
    }
}
