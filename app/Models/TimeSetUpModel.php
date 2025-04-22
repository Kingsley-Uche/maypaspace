<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeSetUpModel extends Model
{
    protected $table = 'time_set_ups'; // Table name

    protected $fillable = [
        'tenant_id',
        'location_id',
        'day',
        'open_time',
        'close_time',
        'total_minutes',
    ];

    /**
     * Relationships
     */
    
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
