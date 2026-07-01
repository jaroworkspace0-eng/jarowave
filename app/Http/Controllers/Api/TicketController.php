// app/Http/Controllers/Api/TicketController.php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->query('type', 'platform');

        $tickets = Ticket::where('user_id', $request->user()->id)
            ->where('type', $type)
            ->latest()
            ->get();

        return response()->json(['tickets' => $tickets]);
    }

    public function show(Request $request, Ticket $ticket)
    {
        if ($ticket->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $ticket->load(['replies' => fn ($q) => $q->where('is_internal_note', false), 'replies.user:id,name']);

        return response()->json(['ticket' => $ticket]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:platform,estate',
            'category' => 'required|string|max:50',
            'subject' => 'required|string|max:150',
            'description' => 'required|string|max:5000',
            'priority' => 'required|in:low,medium,high,urgent',
            'channel_id' => 'required_if:type,estate|integer',
        ]);

        $user = $request->user();
        $employee = $user->employee;

        $channelId = null;
        $clientId = null;

        if ($validated['type'] === 'estate') {
            if (!$employee) {
                return response()->json(['message' => 'No estate profile found for this user.'], 422);
            }

            $ownsChannel = $employee->channels()->where('channels.id', $validated['channel_id'])->exists();

            if (!$ownsChannel) {
                return response()->json(['message' => 'You are not linked to this estate.'], 403);
            }

            $channelId = $validated['channel_id'];
            $clientId = $employee->client_id;
        }

        $ticket = Ticket::create([
            'type' => $validated['type'],
            'category' => $validated['category'],
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'priority' => $validated['priority'],
            'user_id' => $user->id,
            'channel_id' => $channelId,
            'client_id' => $clientId,
        ]);

        return response()->json(['ticket' => $ticket], 201);
    }

    public function reply(Request $request, Ticket $ticket)
    {
        if ($ticket->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate(['message' => 'required|string|max:5000']);

        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'message' => $validated['message'],
        ]);

        if ($ticket->status === 'resolved') {
            $ticket->update(['status' => 'open']);
        }

        return response()->json(['reply' => $reply], 201);
    }
}