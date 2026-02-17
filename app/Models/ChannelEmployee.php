<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelEmployee extends Model
{
    protected $table = 'channel_employee';

    protected $fillable = [
        'employee_id',
        'channel_id',
        'is_online',
        'last_seen',
    ];

    public function channel() {
        return $this->hasMany(Channel::class);
    }
}
