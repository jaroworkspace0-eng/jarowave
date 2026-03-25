<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserStatusLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['user_id', 'status', 'logged_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
