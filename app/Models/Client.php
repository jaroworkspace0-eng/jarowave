<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    protected $table = "clients";
    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
        'address',
        'is_active',
    ];

    public function channels() {
        return $this->hasMany(Channel::class);
    }

    public function employees() {
        return $this->hasMany(Employee::class);
    }

    public function user() {
        return $this->belongsTo(User::class);
    }

}
