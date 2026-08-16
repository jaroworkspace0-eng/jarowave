<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantAddressHistory extends Model
{
    protected $fillable = [
        'user_id', 'channel_id', 'address_line_1', 'suburb',
        'complex_name', 'unit_number', 'latitude', 'longitude',
        'effective_from', 'effective_to', 'is_inherited', 'source_user_id'
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to'   => 'datetime',
    ];
}
