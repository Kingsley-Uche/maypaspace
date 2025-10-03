<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentListing extends Model
{
    use HasFactory;

    // Table name is "payment_listings" by default, so you can omit $table

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'payment_name',
        'fee',
        'book_spot_id',
        'tenant_id',
        'payment_by_user_id',
        'payment_completed',
    ];

    /**
     * Relationships
     */
    public function bookSpot()
    {
        return $this->belongsTo(BookSpot::class, 'book_spot_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function paymentBy()
    {
        // assuming users table maps to App\Models\User
        return $this->belongsTo(User::class, 'payment_by_user_id');
    }
}
