<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservedSpots extends Model
{
    //
    protected $fillable = [
        'user_id',
        'spot_id',
        'day',
        'start_time',
        'end_time',
        'expiry_day',
    ];
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'expiry_day' => 'datetime',
    ];
}
