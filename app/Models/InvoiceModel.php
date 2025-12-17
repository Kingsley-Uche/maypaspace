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
        'booked_by_user_id',
        'tenant_id',
        'status',
    ];
    protected $casts = [
        'user_id' => 'integer',
        'invoice_ref' => 'string',
        'amount' => 'decimal:2',
        'book_spot_id' => 'integer',
        'booked_by_user_id' => 'integer',
    ];
    protected $table = 'invoices';
    protected $primaryKey = 'id';

    public static function generateInvoiceRef()
{
    do {
        $code = 'INV-' . date('YmdHis') . '-' . str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    } while (InvoiceModel::where('invoice_ref', $code)->exists());

    return $code;
}
public function bookSpot()
{
    return $this->belongsTo(BookSpot::class, 'book_spot_id')->withTrashed();
}

public function user()
{
    return $this->belongsTo(User::class, 'user_id');    

}
public function spacePayment()
{
    return $this->hasMany(SpacePaymentModel::class, 'invoice_ref', 'invoice_ref');
}

}