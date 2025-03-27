<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookSpot extends Model
{
    // Specify the table associated with the model (if it differs from the plural form of the model name)
    protected $table = 'book_spots';

    // Specify the attributes that are mass assignable
    protected $fillable = [
        'start_time',
        'end_time',
        'booked_by_user',
        'fee',
        'user_id',
        'spot_id',
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
   
}
