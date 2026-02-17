<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('status-updates', function ($user) {
    return (bool) $user;
});
