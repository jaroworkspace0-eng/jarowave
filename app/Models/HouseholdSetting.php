<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HouseholdSetting extends Model
{
      protected $fillable = [
        'user_id', // user_id is the household_id for this setting
        'auto_accept',
        'sos_alerts',
        'all_clear',
        'appear_in_search',
        'show_suburb',
        'sound_vibrate',
    ];

    protected $casts = [
        'auto_accept'      => 'boolean',
        'sos_alerts'       => 'boolean',
        'all_clear'        => 'boolean',
        'appear_in_search' => 'boolean',
        'show_suburb'      => 'boolean',
        'sound_vibrate'    => 'boolean',
    ];
}
