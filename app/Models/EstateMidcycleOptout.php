<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstateMidcycleOptout extends Model
{
    protected $fillable = [
        'user_id',
        'channel_id',
        'channel_subscription_id',
        'amount_owed',
        'opted_out_at',
        'billed',
    ];

    protected $casts = [
        'opted_out_at' => 'datetime',
        'amount_owed'  => 'decimal:2',
        'billed'       => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function channelSubscription(): BelongsTo
    {
        return $this->belongsTo(ChannelSubscription::class);
    }
}