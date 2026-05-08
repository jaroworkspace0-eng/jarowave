<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedHousehold extends Model
{
    protected $fillable = ['user_id', 'blocked_user_id'];

    public function blockedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_user_id');
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
