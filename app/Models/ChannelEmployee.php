<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChannelEmployee extends Model
{
    use SoftDeletes;
    
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
