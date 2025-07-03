<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['name', 'price', 'num_of_locations', 'num_of_users'];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
