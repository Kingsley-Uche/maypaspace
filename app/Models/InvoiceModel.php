<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceModel extends Model
{
    //
    protected $fillable = [
        'user_id',
        'invoice_ref',
        'amount',
        'book_spot_id',
        'booked_user_id',
    ];
    protected $casts = [
        'user_id' => 'integer',
        'invoice_ref' => 'string',
        'amount' => 'decimal:2',
        'book_spot_id' => 'integer',
        'booked_user_id' => 'integer',
    ];
    protected $table = 'invoices';
    protected $primaryKey = 'id';
}
