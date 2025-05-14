<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxModel extends Model
{
    // Table name is automatically "tax_models" based on the class name,
    // but you can explicitly set it if needed:
     protected $table = 'tax_models';

    // Mass assignable attributes
    protected $fillable = [
        'name',
        'description',
        'percentage',
        'tenant_id',
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
