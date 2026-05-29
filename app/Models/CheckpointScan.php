<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckpointScan extends Model
{
      protected $fillable = [
        'checkpoint_id',
        'guard_id',
        'note',
        'scanned_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }

    public function securityGuard(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guard_id');
    }
}
