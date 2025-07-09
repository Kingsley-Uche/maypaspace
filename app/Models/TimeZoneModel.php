<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeZoneModel extends Model
{
    // Optional if the table name does not follow Laravel convention
    protected $table = 'time_zone_models';

    // Allow mass assignment
    protected $fillable = [
        'tenant_id',
        'utc_time_zone',
        'location_id',
    ];

    // Relationships (optional, but common)

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
