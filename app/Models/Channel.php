<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Channel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'category',
        'type',
        'is_active',
    ];

    public function client(){
        return $this->belongsTo(Client::class);
    }

    public function employees()
    {
        // Change hasMany to belongsToMany
        return $this->belongsToMany(Employee::class, 'channel_employee')
                    ->withPivot('last_seen') // This allows us to see when they were last on THIS channel
                    ->withTimestamps();
    }

    // public function employees(){
    //     return $this->hasMany(Employee::class);
    // }

    public function channelEmployees(){
        return $this->belongsToMany(ChannelEmployee::class, 'channel_id');
    }
}
