<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HouseholdInvite extends Model
{
    protected $fillable = ['client_id', 'token', 'expires_at', 'uses', 'max_uses', 'channel_id'];

    protected $casts = ['expires_at' => 'datetime'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
}