<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountDeletionRequest extends Model
{
    protected $fillable = [
        'user_id', 'name', 'email', 'phone', 'reason',
        'notes', 'status', 'requested_at', 'scheduled_deletion_at',
        'processed_at', 'processed_by', 'admin_notes',
    ];

    protected $casts = [
        'requested_at'        => 'datetime',
        'scheduled_deletion_at' => 'datetime',
        'processed_at'        => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}