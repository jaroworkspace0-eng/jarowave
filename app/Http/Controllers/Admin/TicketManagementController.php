<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TicketManagementController extends Controller
{
    // GET /admin/platform-tickets or /estate/tickets — $scope passed by route
    public function index(Request $request, string $scope)
    {
        $query = Ticket::query()->where('type', $scope)->latest();

        if ($scope === 'estate') {
            $query->where('client_id', $request->user()->client_id);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $tickets = $query->with('user:id,name')->paginate(20)->withQueryString();

        return Inertia::render('Admin/Tickets/Index', [
            'tickets' => $tickets,
            'scope' => $scope,
            'filters' => $request->only('status'),
        ]);
    }

    public function show(Request $request, Ticket $ticket)
    {
        $this->authorize('manage', $ticket);

        $ticket->load(['replies.user:id,name,role', 'user:id,name,email', 'assignee:id,name']);

        return Inertia::render('Admin/Tickets/Show', [
            'ticket' => $ticket,
        ]);
    }

    public function reply(Request $request, Ticket $ticket)
    {
        $this->authorize('manage', $ticket);

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
            'is_internal_note' => 'boolean',
        ]);

        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'message' => $validated['message'],
            'is_internal_note' => $validated['is_internal_note'] ?? false,
        ]);

        if (!($validated['is_internal_note'] ?? false) && $ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        return back()->with('success', 'Reply sent.');
    }

    public function updateStatus(Request $request, Ticket $ticket)
    {
        $this->authorize('manage', $ticket);

        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        match ($validated['status']) {
            'resolved' => $ticket->markResolved(),
            'closed' => $ticket->markClosed(),
            default => $ticket->update(['status' => $validated['status']]),
        };

        return back()->with('success', 'Status updated.');
    }
}