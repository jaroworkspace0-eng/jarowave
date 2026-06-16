<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'category',
        'channel_type',
        'billing_model',
        'amount_per_household',
        'is_active',
        'guard_fixed_amount',
        'security_pool',
        'security_percentage',
    ];
    // ── Relationships ──

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'channel_employee')
                    ->withPivot('last_seen')
                    ->withTimestamps();
    }

    public function channelEmployees()
    {
        return $this->belongsToMany(ChannelEmployee::class, 'channel_id');
    }

    public function billingContact(): HasOne
    {
        return $this->hasOne(ChannelBillingContact::class)->where('is_active', true);
    }

    public function channelSubscriptions(): HasMany
    {
        return $this->hasMany(ChannelSubscription::class);
    }

    public function activeChannelSubscription(): HasOne
    {
        return $this->hasOne(ChannelSubscription::class)
                    ->whereIn('status', ['pending', 'active'])
                    ->latestOfMany();
    }
}