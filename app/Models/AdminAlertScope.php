<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAlertScope extends Model
{
    protected $fillable = ['admin_id', 'scope_type', 'scope_id'];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}