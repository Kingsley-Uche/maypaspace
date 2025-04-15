<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Scopes\TenantScope;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens;
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'user_type_id',
        'tenant_id',
    ];

    public function readNotifications()
    {
        return $this->belongsToMany(Notification::class, 'notification_reads')->withPivot('read_at')->withTimestamps();
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_users');
    }

    public function user_type()
    {
        return $this->belongsTo(UserType::class, 'user_type_id');
    }
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdLocations()
    {
        return $this->hasMany(Location::class, 'created_by_user_id');
    }

    public function deletedLocations()
    {
        return $this->hasMany(Location::class, 'deleted_by_user_id');
    }

    public function createdFloors()
    {
        return $this->hasMany(Floor::class, 'created_by_user_id');
    }

    public function deletedFloors()
    {
        return $this->hasMany(Floor::class, 'deleted_by_user_id');
    }

    protected static function booted()
{
    static::addGlobalScope(new TenantScope());
}


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
