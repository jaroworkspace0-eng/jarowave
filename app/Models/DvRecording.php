<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DvRecording extends Model
{
      protected $fillable = [
        'alert_id',
        'channel_id',
        'user_id',
        'started_at',
        'ended_at',
        'file_path',
        'chunk_count',
        'duration_secs',
        'is_finalised',
        'cancel_pin_used',
    ];
 
    protected $casts = [
        'started_at'    => 'datetime',
        'ended_at'      => 'datetime',
        'is_finalised'  => 'boolean',
        'duration_secs' => 'float',
        'chunk_count'   => 'integer',
    ];
 
    // Never expose the raw server file path to API consumers
    protected $hidden = ['file_path'];
 
    // ── Relationships ─────────────────────────────────────────
    public function alert(): BelongsTo
    {
        return $this->belongsTo(EmergencyAlert::class, 'alert_id');
    }
 
    public function user(): BelongsTo
    {
        // Replace 'User' with whatever your user model is called
        return $this->belongsTo(User::class, 'user_id');
    }
 
    // ── Appended attributes ───────────────────────────────────
    protected $appends = ['stream_url'];
 
    public function getStreamUrlAttribute(): ?string
    {
        if (!$this->is_finalised) return null;
        return url("/api/dv-recordings/{$this->alert_id}/stream");
    }
}
