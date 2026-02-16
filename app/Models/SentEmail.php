<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SentEmail extends Model
{
    protected $fillable = [
        'content',
        'subject',
        'user_id',
        'tenant_id'
    ];

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attachments(){
        return $this->hasMany(EmailAttachment::class, 'sent_email_id');
    }
}
