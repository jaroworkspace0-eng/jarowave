<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioBroadcast extends Model
{
    protected $fillable = [
    'user_id',
    'file_path',
    'file_url',
    'duration',
    'channel_id',
];
}
