<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Checkpoint extends Model
{
    protected $fillable = [
        'client_id',
        'name',
        'token',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Auto-generate token before creating
    protected static function booted(): void
    {
        static::creating(function (Checkpoint $checkpoint) {
            $checkpoint->token = 'CHK_' . strtoupper(Str::random(8));
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(CheckpointScan::class);
    }
}
