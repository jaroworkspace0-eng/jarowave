<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\ChannelBillingContact;
use App\Models\Employee;
use Illuminate\Http\Request;

class EstateTenantController extends Controller
{
    // Only channels this billing contact is actively responsible for —
    // never trust a channel_id the client sends.
    private function myChannelIds(Request $request): array
    {
        return ChannelBillingContact::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->pluck('channel_id')
            ->toArray();
    }

    public function channels(Request $request)
    {
        return Channel::whereIn('id', $this->myChannelIds($request))
            ->get(['id', 'name']);
    }

    public function index(Request $request)
    {
        $channelIds = $this->myChannelIds($request);

        $households = Employee::whereHas('channels', fn ($q) =>
                $q->whereIn('channels.id', $channelIds))
            ->whereHas('user', fn ($q) =>
                $q->whereIn('occupation', ['household', 'resident'])
                  ->when($request->search, fn ($sq, $s) =>
                      $sq->where('name', 'like', "%{$s}%")
                         ->orWhere('email', 'like', "%{$s}%")))
            ->with(['user.subscription', 'channels'])
            ->paginate(20);

        return response()->json([
            'households' => $households,
            'household_total' => $households->total(),
        ]);
    }

    public function store(Request $request)
    {
        $channelIds = $this->myChannelIds($request);

        if (!in_array($request->input('channel_id'), $channelIds)) {
            abort(403, 'You do not manage this channel.');
        }

        // ...same Employee/User creation logic as the existing
        // /api/employees household branch, just forced to this channel
    }

    public function update(Request $request, Employee $employee)
    {
        $channelIds = $this->myChannelIds($request);

        // guard: employee must currently belong to one of my channels,
        // and any new channel_id in the payload must also be in $channelIds
    }

    public function destroy(Request $request, Employee $employee)
    {
        $channelIds = $this->myChannelIds($request);
        abort_unless(
            $employee->channels()->whereIn('channels.id', $channelIds)->exists(),
            403,
        );
        // ...delete
    }
}
