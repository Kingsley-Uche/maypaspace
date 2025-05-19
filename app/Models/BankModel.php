<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankModel extends Model
{
    protected $fillable = [
        'account_name',
        'account_number',
        'bank_name',
        'location_id',
        'tenant_id',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }           
}
