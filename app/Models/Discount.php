<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $fillable = [
        'user_type_id',
        'discount',
        'tenant_id',
    ];

    public function userType()
{
    return $this->belongsTo(UserType::class);
}

}
