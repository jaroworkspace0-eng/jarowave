<?php

namespace App\Http\Controllers\Api\Estate;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;

class EstateTicketController extends Controller
{
    private function clientId(Request $request): ?int
    {
        return $request->user()->employee?->client_id;
    }

    public function index(Request $request)
    {
        $clientId = $this->clientId($request);

        if (!$clientId) {
            return response()->json(['tickets' => []]);
        }

        $query = Ticket::where('type', 'estate')
            ->where('client_id', $clientId)
            ->with('user:id,name,email')
            ->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(['tickets' => $query->get()]);
    }

    public function show(Request $request, Ticket $ticket)
    {
        abort_if($ticket->client_id !== $this->clientId($request), 403);

        $ticket->load(['replies.user:id,name,role', 'user:id,name,email', 'assignee:id,name']);
        return response()->json(['ticket' => $ticket]);
    }

    public function reply(Request $request, Ticket $ticket)
    {
        abort_if($ticket->client_id !== $this->clientId($request), 403);

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
            'is_internal_note' => 'boolean',
        ]);

        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'message' => $validated['message'],
            'is_internal_note' => $validated['is_internal_note'] ?? false,
        ]);

        if (!($validated['is_internal_note'] ?? false) && $ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        return response()->json(['reply' => $reply], 201);
    }

    public function updateStatus(Request $request, Ticket $ticket)
    {
        abort_if($ticket->client_id !== $this->clientId($request), 403);

        $validated = $request->validate(['status' => 'required|in:open,in_progress,resolved,closed']);

        $ticket->update([
            'status' => $validated['status'],
            'resolved_at' => $validated['status'] === 'resolved' ? now() : $ticket->resolved_at,
            'closed_at' => $validated['status'] === 'closed' ? now() : $ticket->closed_at,
        ]);

        return response()->json(['ticket' => $ticket->fresh()]);
    }
}