<?php

namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;


class Admin extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'role_id',
        'password',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    protected $hidden = [
        'password',
        'remember_taken',
    ];
}
