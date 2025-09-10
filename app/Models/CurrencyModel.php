<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CurrencyModel extends Model
{
    use HasFactory;

    protected $table = 'curreny_models'; // matches your migration

    protected $fillable = [
        'name',
        'symbol',
        'location_id',
        'tenant_id',
    ];

    /**
     * Relationships
     */

    // A currency belongs to a location
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    // A currency belongs to a tenant
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
