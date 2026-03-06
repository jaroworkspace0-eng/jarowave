<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmergencyAlert extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'channel_id',
        'client_id',
        'latitude',
        'longitude',
        'accuracy',
        'is_resolved',
        'resolved_at',
        'resolved_by'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by'); // Assuming the resolver is also a user (user_id)
    }
}
