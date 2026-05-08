<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
// use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'name',
        'organisation_type',
        'organisation_name',
        'plan',
        'billing_cycle',
        'email',
        'role',
        'phone',
        'occupation',
        'password',
        'safe_cancel_pin',
        'duress_pin',
        'is_active',
        'status',
        'address_line_1',
        'complex_name',
        'suburb',
        'access_code',
        'unit_number',
        'latitude',
        'longitude',
        'fcm_token',
        'fcm_device_id',
        'fcm_token_updated_at',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'password'                => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'fcm_token_updated_at'    => 'datetime',
        ];
    }

    // ── Existing relationships ────────────────────────────────────

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function client()
    {
        return $this->hasOne(Client::class);
    }

    public function channels()
    {
        return $this->belongsToMany(Channel::class, 'channel_employee');
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class)->latest();
    }

    public function isHousehold(): bool
    {
        return in_array(strtolower($this->role), ['household', 'resident']);
    }

    // ── Pairing relationships ─────────────────────────────────────

    // Pair requests this user sent
    public function sentPairings(): HasMany
    {
        return $this->hasMany(HouseholdPairing::class, 'requester_id');
    }

    // Pair requests this user received
    public function receivedPairings(): HasMany
    {
        return $this->hasMany(HouseholdPairing::class, 'receiver_id');
    }

    // All active guardians (both directions merged)
    public function activeGuardians(): Collection
    {
        $asRequester = $this->sentPairings()
            ->active()
            ->with('receiver')
            ->get()
            ->pluck('receiver');

        $asReceiver = $this->receivedPairings()
            ->active()
            ->with('requester')
            ->get()
            ->pluck('requester');

        return $asRequester->merge($asReceiver); // ← pluck() + merge() = Support\Collection
    }

    // Pending incoming pair requests
    public function pendingPairingRequests(): HasMany
    {
        return $this->receivedPairings()->where('status', 'pending');
    }

    // Guardian responses this user has made
    public function guardianResponses(): HasMany
    {
        return $this->hasMany(GuardianResponse::class, 'guardian_household_id');
    }

    // Reports this user has submitted as a guardian
    public function guardianReports(): HasMany
    {
        return $this->hasMany(GuardianReport::class, 'reporting_household_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function unreadNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class)->where('is_read', false);
    }

    public function householdSetting(): HasOne
    {
        return $this->hasOne(HouseholdSetting::class, 'user_id');
    }
}