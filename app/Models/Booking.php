<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\TenantScope;

class Booking extends Model
{
    protected $fillable = [
        'booking_date',
        'booking_start_time',
        'booking_end_time',
        'attendee',
        'product_id',
        'tenant_id',
        'booked_by',
      ];

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
