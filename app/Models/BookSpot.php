<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookSpot extends Model
{
    use SoftDeletes; // You have softDeletes in your migration, so you should use it here too.

    protected $table = 'book_spots';

    protected $fillable = [
        'start_time',
        'end_time',
        'booked_by_user',
        'fee',
        'tenant_id',
        'user_id',
        'spot_id',
        'booked_ref_id',
        'invoice_ref',
        'type',
        'chosen_days',
        'recurrence',
        'expiry_day',
        'number_months',
        'number_weeks',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'chosen_days' => 'array', // Important: cast chosen_days (JSON) to array automatically
    ];

    public function bookedByUser()
    {
        return $this->belongsTo(User::class, 'booked_by_user');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function spot()
    {
        return $this->belongsTo(Spot::class);
    }

    public function bookedRef()
    {
        return $this->belongsTo(BookedRef::class, 'booked_ref_id');
    }

    public function invoice(){
        return $this->belongsTo(InvoiceModel::class, 'invoice_ref', 'invoice_ref');
    }

    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
