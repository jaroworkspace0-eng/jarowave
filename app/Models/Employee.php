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

    public function channels()
    {
        return $this->belongsToMany(Channel::class, 'channel_employee', 'employee_id', 'channel_id')
            ->withPivot('is_online', 'last_seen')
            ->withTimestamps();
    }

    public function user() {
        return $this->belongsTo(User::class);
    }

     public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('user_id', $value)->firstOrFail();
    }
}
