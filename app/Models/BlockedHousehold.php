<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedHousehold extends Model
{
    protected $fillable = ['user_id', 'blocked_user_id'];
}
