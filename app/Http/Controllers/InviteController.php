<?php

namespace App\Http\Controllers;

use App\Models\HouseholdInvite;
use Illuminate\Http\Request;

class InviteController extends Controller
{

    private function buildUrl(string $token): string
    {
        return 'https://account.jaroworkspace.com/register.html?token=' . $token;
    }
    // GET /api/invite
    public function show(Request $request)
    {
        $client = $request->user()->client;

        if (!$client) {
            return response()->json(['invites' => []]);
        }

        $invites = HouseholdInvite::where('client_id', $client->id)
            ->with('channel')
            ->get()
            ->map(fn($i) => [
                'id'           => $i->id,
                'channel_id'   => $i->channel_id,
                'channel_name' => $i->channel?->name ?? 'Unknown Channel',
                'invite_url'   => $this->buildUrl($i->token),
                'uses'         => $i->uses,
                'token'        => $i->token,
            ]);

        return response()->json(['invites' => $invites]);
    }


    // POST /api/invite/generate
    public function generate(Request $request)
    {
        $request->validate([
            'channel_id' => 'required|exists:channels,id',
        ]);

        $client = $request->user()->client;

        // One link per channel — create if doesn't exist, never rotate automatically
        $invite = HouseholdInvite::firstOrCreate(
            ['client_id' => $client->id, 'channel_id' => $request->channel_id],
            ['token' => bin2hex(random_bytes(32))]
        );

        return response()->json([
            'id'           => $invite->id,
            'channel_id'   => $invite->channel_id,
            'channel_name' => $invite->channel?->name,
            'invite_url' => $this->buildUrl($invite->token),
            'token'      => $invite->token,
            'uses'         => $invite->uses ?? 0,
        ]);
    }

    // POST /api/invite/{id}/regenerate
    public function regenerate(Request $request, $id)
    {
        $client = $request->user()->client;

        $invite = HouseholdInvite::where('id', $id)
            ->where('client_id', $client->id)
            ->firstOrFail();

        $invite->update(['token' => bin2hex(random_bytes(32))]);

        return response()->json([
            'id'           => $invite->id,
            'channel_id'   => $invite->channel_id,
            'channel_name' => $invite->channel?->name,
            'invite_url'   => $this->buildUrl($invite->token),
            'token'        => $invite->token,
            'uses'         => $invite->uses,
        ]);
    }


    // DELETE /api/invite/{id}
    public function destroy(Request $request, $id)
    {
        $client = $request->user()->client;

        $invite = HouseholdInvite::where('id', $id)
            ->where('client_id', $client->id)
            ->firstOrFail();

        $invite->delete();

        return response()->json(['message' => 'Invite link deleted.']);
    }

    // GET /api/household/invite/{token}
    public function validate($token)
    {
        $invite = HouseholdInvite::where('token', $token)
            ->with('client.user')
            ->first();

        if (!$invite) {
            return response()->json(['error' => 'Invalid invite link'], 404);
        }

        if ($invite->expires_at && $invite->expires_at->isPast()) {
            return response()->json(['error' => 'This invite link has expired'], 410);
        }

        if ($invite->max_uses && $invite->uses >= $invite->max_uses) {
            return response()->json(['error' => 'This invite link has reached its limit'], 410);
        }

        return response()->json([
            'group' => [
                'organisation_name' => $invite->client->user->organisation_name,
                'area'              => $invite->client->user->address_line_1 ?? null,
            ],
        ]);
    }
}
