<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'primary_account_id', 'linked_account_id', 'status',
        'escalated', 'escalated_at',
        'approved_by_type', 'approved_by_id', 'approved_at',
    ];

    protected $casts = [
        'escalated'     => 'boolean',
        'escalated_at'  => 'datetime',
        'approved_at'   => 'datetime',
    ];

    public function primaryAccount()
    {
        return $this->belongsTo(User::class, 'primary_account_id');
    }

    public function linkedAccount()
    {
        return $this->belongsTo(User::class, 'linked_account_id');
    }
}