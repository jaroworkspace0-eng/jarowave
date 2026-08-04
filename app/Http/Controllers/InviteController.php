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


    private function myChannelIds(Request $request): array
    {
        return \App\Models\ChannelBillingContact::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->pluck('channel_id')
            ->toArray();
    }

    private function userCanManageChannel(Request $request, int $channelId): bool
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'estate_billing') {
            return in_array($channelId, $this->myChannelIds($request), true);
        }

        return $user->employee?->ch()->where('channels.id', $channelId)->exists() ?? false;
    }


    // GET /api/invite
    // GET /api/invite
    public function show(Request $request)
    {
        $clientId = $this->resolveClientId($request);

        if (!$clientId) {
            return response()->json(['invites' => []]);
        }

        $query = HouseholdInvite::whereHas('channel', fn($q) => $q->where('client_id', $clientId))
            ->with('channel');

        $user = $request->user();
        if ($user->role === 'estate_billing') {
            $query->whereIn('channel_id', $this->myChannelIds($request));
        } elseif ($user->role !== 'admin') {
            $channelIds = $user->employee?->ch()->pluck('channels.id') ?? collect();
            $query->whereIn('channel_id', $channelIds);
        }

        $invites = $query->get()->map(fn($i) => [
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

        $clientId = $this->resolveClientId($request);

        if (!$clientId) {
            return response()->json(['message' => 'Please select a client first.'], 422);
        }

        if (!$this->userCanManageChannel($request, (int) $request->channel_id)) {
            return response()->json(['message' => 'You do not manage this channel.'], 403);
        }

        // One link per channel — create if doesn't exist, never rotate automatically
        $invite = HouseholdInvite::firstOrCreate(
            ['client_id' => $clientId, 'channel_id' => $request->channel_id],
            ['token' => bin2hex(random_bytes(32))]
        );

        return response()->json([
            'id'           => $invite->id,
            'channel_id'   => $invite->channel_id,
            'channel_name' => $invite->channel?->name,
            'invite_url'   => $this->buildUrl($invite->token),
            'token'        => $invite->token,
            'uses'         => $invite->uses ?? 0,
        ]);
    }

    public function regenerate(Request $request, $id)
    {
        $user = $request->user();
        $query = HouseholdInvite::where('id', $id);

        if ($user->role !== 'admin') {
            $query->where('client_id', $this->resolveClientId($request));
        }

        $invite = $query->firstOrFail();

        if (!$this->userCanManageChannel($request, $invite->channel_id)) {
            abort(403, 'You do not manage this channel.');
        }

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

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $query = HouseholdInvite::where('id', $id);

        if ($user->role !== 'admin') {
            $query->where('client_id', $this->resolveClientId($request));
        }

        $invite = $query->firstOrFail();

        if (!$this->userCanManageChannel($request, $invite->channel_id)) {
            abort(403, 'You do not manage this channel.');
        }

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

   private function resolveClientId(Request $request): ?int
{
    $user = $request->user();

    if ($user->role === 'admin') {
        return $request->query('client_id') ?? $request->input('client_id');
    }

    return $user->client?->id ?? $user->employee?->client_id;
}

}
