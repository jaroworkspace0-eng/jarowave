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
        'target_employee_ids',
        'target_channel_ids',
        'department',
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
        'target_employee_ids' => 'array',
        'target_channel_ids' => 'array',
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
