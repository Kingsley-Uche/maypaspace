<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookedRef extends Model
{
    //
    protected $fillable = [
        'booked_ref',
        'booked_by_user',
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
    public function bookedSpot()
    {
        return $this->hasMany(BookSpot::class, 'booked_ref');
    }
    public function bookedSpotRef()
    {
        return $this->hasMany(BookedRef::class, 'booked_ref');
    }
    public function bookedSpotRefByUser()
    {
        return $this->hasMany(BookedRef::class, 'booked_by_user');
    }
    public function bookedSpotRefByUserId()
    {
        return $this->hasMany(BookedRef::class, 'user_id');
    }
    public function generateRef($tenant_slug)
    {
        $maxSlugLength = 25 - strlen('BOOKED-YYYY-MM-DD-HH-MM-SS'); // 6 + 14 = 20, so 5 chars for slug
        $trimmedSlug = substr($tenant_slug, 0, $maxSlugLength);
        $booked_ref =  $trimmedSlug . '-' .'booked -'.date('Y-m-d-H-i-s');
        $counter = 0;
        $original_ref = $booked_ref;
        
        while (Bookedref::where('booked_ref', $booked_ref)->exists()) {
            $counter++;
            // Trim original ref and add counter to stay within 25 chars
            $suffix = '-' . $counter;
            $baseLength = 25 - strlen($suffix);
            $booked_ref = substr($original_ref, 0, $baseLength) . $suffix;
        }
        
        return $booked_ref;
    }
}
