<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = [
        'title', 'message', 'type', 'target',
        'target_client_id', 'target_user_ids', 'sent_by', 'sent_at',
    ];

    protected $casts = [
        'target_user_ids' => 'array',
        'sent_at'         => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'target_client_id');
    }
}
