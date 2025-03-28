<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Spot extends Model
{
    protected $fillable = [
        'book_status',
        'space_id',
        'location_id',
        'floor_id',
        'tenant_id',
    ];

    public function space()
    {
        return $this->belongsTo(Space::class, 'space_id');
    }
    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
