<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        if ($ticket->user_id === $user->id) return true;
        if ($user->role === 'admin' && $ticket->type === 'platform') return true;
        if ($ticket->type === 'estate' && $ticket->client_id === $user->client_id) return true;
        return false;
    }

    public function manage(User $user, Ticket $ticket): bool
    {
        if ($user->role === 'admin' && $ticket->type === 'platform') return true;
        if ($ticket->type === 'estate' && $ticket->client_id === $user->client_id && $user->role !== 'household') return true;
        return false;
    }
}