<?php

namespace App\Models;
use App\Models\Scopes\TenantScope;

use Illuminate\Database\Eloquent\Model;

class TeamUser extends Model
{
    protected $fillable = [
        'team_id',
        'user_id',
        'manager',
        'tenant_id',
    ];

    protected $table = 'team_users'; 

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
