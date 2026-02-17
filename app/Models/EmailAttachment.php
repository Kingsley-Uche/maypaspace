<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAttachment extends Model
{
    protected $fillable = [
        'sent_email_id',
        'name',
        'path'
    ];
}
