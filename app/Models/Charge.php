<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Charge extends Model
{
    
    protected $table = 'charges';

    protected $fillable = [
        'name',
        'tenant_id',
        'space_id',
        'is_fixed',
        'value',
    ];

    protected $casts = [
        'is_fixed' => 'boolean',
        'value'    => 'decimal:2', // keep money/percent precise
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function space()
    {
        return $this->belongsTo(Space::class);
    }

   
}
