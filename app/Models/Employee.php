<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'client_id',
        'channel_id'
    ];

    public function client() {
        return $this->belongsTo(Client::class);
    }


    public function channel() {
        return $this->belongsToMany(Channel::class)
        ->withPivot('is_online', 'last_seen')
        ->withTimestamps();
        // return $this->belongsTo(Channel::class);
    }

    public function user() {
        return $this->belongsTo(User::class);
    }
}
