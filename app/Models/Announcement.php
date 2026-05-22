<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = [
        'title',
        'message',
        'type',
        'target',
        'target_client_ids',
        'target_user_ids',
        'target_household_ids',
        'payment_subtype',
        'app_version',
        'playstore_url',
        'min_version_code',
        'force_update',
        'sent_by',
        'sent_at',
    ];

    protected $casts = [
        'target_client_ids'    => 'array',
        'target_user_ids'      => 'array',
        'target_household_ids' => 'array',
        'force_update'         => 'boolean',
        'sent_at'              => 'datetime',
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
