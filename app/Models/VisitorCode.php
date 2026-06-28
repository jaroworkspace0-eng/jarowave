<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VisitorCode extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'visit_type',
        'visitor_name',
        'visitor_phone',
        'visitor_id_number',
        'vehicle_registration',
        'delivery_company',
        'notes',
        'code',
        'qr_token',
        'status',
        'expected_at',
        'expires_at',
        'day_expires_at',
        'arrived_at',
        'departed_at',
        'arrived_verified_by',
        'departed_verified_by',
        'licence_raw',
        'licence_scanned_at',
        'licence_id_number',
        'licence_name',
        'licence_surname',
        'licence_expiry',
        'licence_codes',
    ];

    protected $casts = [
        'expected_at'    => 'datetime',
        'expires_at'     => 'datetime',
        'day_expires_at' => 'datetime',
        'arrived_at'     => 'datetime',
        'departed_at'    => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function arrivedVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'arrived_verified_by');
    }

    public function departedVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'departed_verified_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        if ($this->status === 'expired' || $this->status === 'revoked') return true;
        if ($this->status === 'pending' && now()->isAfter($this->expires_at)) return true;
        if ($this->status === 'arrived' && $this->day_expires_at && now()->isAfter($this->day_expires_at)) return true;
        return false;
    }

    public function isPending(): bool  { return $this->status === 'pending';  }
    public function isArrived(): bool  { return $this->status === 'arrived';  }
    public function isDeparted(): bool { return $this->status === 'departed'; }

    // ── Static generators ─────────────────────────────────────────────────────

    public static function generateUniqueCode(): string
    {
        do {
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('code', $code)->whereIn('status', ['pending', 'arrived'])->exists());

        return $code;
    }

    public static function generateQrToken(): string
    {
        return (string) Str::uuid();
    }
}