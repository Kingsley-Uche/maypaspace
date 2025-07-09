<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = ['company_name', 'slug', 'created_by', 'subscription_id', 'created_by_admin_id', 'company_no_location', 'company_countries', 'paystack_secret_key'];

    protected $hidden = [
        'paystack_secret_key',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
    public function locations()
    {
        return $this->hasMany(Location::class);
    }
    public function bankAccounts()
    {
        return $this->hasMany(BankModel::class, 'tenant_id');
    }
}
