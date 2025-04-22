<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpacePaymentModel extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'spot_id',
        'amount',
        'payment_status',
        'payment_method', //prepaid or postpaid
        'payment_ref',
        'invoice_ref',
    ];

    
}
