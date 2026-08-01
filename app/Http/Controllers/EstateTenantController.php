<?php

namespace App\Http\Controllers;

use App\Mail\HouseholdWelcomeMail;
use App\Models\Channel;
use App\Models\ChannelBillingContact;
use App\Models\Employee;
use App\Models\User;
use App\Services\ChannelBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EstateTenantController extends Controller
{

    public function __construct(
        protected EmployeeController $employeeController,
        protected ChannelBillingService $billingService,
    ) {}

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
        $user = $request->user();
        $channelIds = $this->myChannelIds($request);

        return Channel::whereIn('id', $channelIds)
            ->get(['id', 'name', 'client_id'])
            ->map(fn ($channel) => [
                'id'             => $channel->id,
                'name'           => $channel->name,
                'client_id'      => $channel->client_id,
                'address_line_1' => $user->address_line_1,
                'suburb'         => $user->suburb,
            ]);
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

        $validated = $request->validate([
            'channel_id'      => 'required|integer',
            'name'            => 'required|string|max:255',
            'email'           => 'required|email|max:255|unique:users,email',
            'phone'           => 'required|string|max:20',
            'unit_number'     => 'nullable|string|max:50',
            'safe_cancel_pin' => 'required|string|size:6',
            'duress_pin'      => 'required|string|size:6',
        ]);

        $channel = Channel::whereIn('id', $channelIds)->findOrFail($validated['channel_id']);
        $billingContact = $request->user();
        $plainPassword = Str::password(12);

        return DB::transaction(function () use ($validated, $channel, $billingContact, $plainPassword) {
            $user = User::create([
                'name'            => $validated['name'],
                'email'           => $validated['email'],
                'phone'           => $validated['phone'],
                'password'        => Hash::make($plainPassword),
                'occupation'      => 'household',
                'role'            => 'household',
                'address_line_1'  => $billingContact->address_line_1,
                'suburb'          => $billingContact->suburb,
                'latitude'        => $billingContact->latitude,
                'longitude'       => $billingContact->longitude,
                'complex_name'    => $channel->name,
                'unit_number'     => $validated['unit_number'] ?? null,
                'safe_cancel_pin' => $validated['safe_cancel_pin'],
                'duress_pin'      => $validated['duress_pin'],
            ]);

    $employee = Employee::create([
        'user_id'   => $user->id,
        'client_id' => $channel->client_id,
    ]);

    $employee->channels()->attach($channel->id);

    // Create the individual subscription first (same as any household),
    // then immediately opt in — optInHousehold() finds this subscription
    // and cancels it with cancellation_reason: 'estate_optin' and
    // channel_subscription_id set, leaving a real audit trail instead
    // of skipping subscription creation altogether.
    $this->employeeController->createHouseholdSubscription($user, $channel->client_id, false);
    $this->billingService->optInHousehold($user, $channel);

    $this->employeeController->sendHouseholdWelcomeMail(
        $user, $channel->client_id, $plainPassword, $channel, estateBilled: true,
    );

    return response()->json(['message' => 'Tenant added successfully. Welcome email sent.']);
});
    }

    public function update(Request $request, Employee $employee)
    {
        $channelIds = $this->myChannelIds($request);
        abort_unless(
            $employee->channels()->whereIn('channels.id', $channelIds)->exists(),
            403,
        );

        $newChannelId = $request->input('channel_id');
        abort_unless(in_array($newChannelId, $channelIds), 403);
        $channel = Channel::findOrFail($newChannelId);

        $employee->user->update([
            'name'           => $request->input('name'),
            'email'          => $request->input('email'),
            'phone'          => $request->input('phone'),
            'unit_number'    => $request->input('unit_number'),
            'address_line_1' => $request->user()->address_line_1,
            'suburb'         => $request->user()->suburb,
            'latitude'       => $request->user()->latitude,
            'longitude'      => $request->user()->longitude,
            'complex_name'   => $channel->name,
        ]);

        $employee->channels()->sync([$channel->id]);

        return response()->json(['message' => 'Tenant updated successfully.']);
    }

    public function destroy(Request $request, Employee $employee)
    {
        $channelIds = $this->myChannelIds($request);
        abort_unless(
            $employee->channels()->whereIn('channels.id', $channelIds)->exists(),
            403,
        );

        $userId = $employee->user_id;

        User::where('id', $userId)->delete();
        $employee->delete();

        $this->notifyPttServer('/force-disconnect', [
            'userId' => $userId,
            'reason' => 'user_inactive',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant deleted successfully!',
        ]);
    }

    private function notifyPttServer(string $endpoint, array $payload): void
    {
        try {
            Http::timeout(5)
                ->withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                ->post(env('PTT_SERVER_URL') . $endpoint, $payload);
        } catch (\Exception $e) {
            Log::warning("PTT server notify failed [{$endpoint}]: " . $e->getMessage());
        }
    }


    public function guards(Request $request)
    {
        $channelIds = $this->myChannelIds($request);

        $guards = Employee::whereHas('channels', fn ($q) =>
                $q->whereIn('channels.id', $channelIds))
            ->whereHas('user', fn ($q) => $q->where('is_gate_guard', true))
            ->with('user:id,name,phone,is_gate_guard')
            ->get();

        return response()->json($guards);
    }

    public function toggleDashboardAccess(Request $request, Employee $employee)
    {
        $channelIds = $this->myChannelIds($request);
        abort_unless(
            $employee->channels()->whereIn('channels.id', $channelIds)->exists(),
            403,
        );
        abort_unless($employee->user->is_gate_guard, 422, 'This employee is not a gate guard.');

        $employee->update(['has_dashboard_access' => !$employee->has_dashboard_access]);

        return response()->json([
            'message' => $employee->has_dashboard_access
                ? 'Dashboard access granted.'
                : 'Dashboard access revoked.',
            'has_dashboard_access' => $employee->has_dashboard_access,
        ]);
    }


    public function storeGuard(Request $request)
    {
        $channelIds = $this->myChannelIds($request);

        $validated = $request->validate([
            'channel_id' => 'required|integer',
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|max:255|unique:users,email',
            'phone'      => 'required|string|max:20',
        ]);

        $channel = Channel::whereIn('id', $channelIds)->findOrFail($validated['channel_id']);
        $plainPassword = Str::password(12);

        return DB::transaction(function () use ($validated, $channel, $plainPassword) {
            $user = User::create([
                'name'         => $validated['name'],
                'email'        => $validated['email'],
                'phone'        => $validated['phone'],
                'password'     => Hash::make($plainPassword),
                'occupation'   => 'security_guard',
                'role'         => 'employee',
                'is_gate_guard' => true,
            ]);

            $employee = Employee::create([
                'user_id'              => $user->id,
                'client_id'            => $channel->client_id,
                'has_dashboard_access' => false,
            ]);

            $employee->channels()->attach($channel->id);

            return response()->json([
                'message'       => 'Guard added successfully.',
                'temp_password' => $plainPassword,
            ]);
        });
    }


    public function destroyGuard(Request $request, Employee $employee)
    {
        $channelIds = $this->myChannelIds($request);
        abort_unless(
            $employee->channels()->whereIn('channels.id', $channelIds)->exists(),
            403,
        );
        abort_unless($employee->user->is_gate_guard, 422, 'This employee is not a gate guard.');

        $userId = $employee->user_id;
        User::where('id', $userId)->delete();
        $employee->delete();

        $this->notifyPttServer('/force-disconnect', [
            'userId' => $userId,
            'reason' => 'user_inactive',
        ]);

        return response()->json(['message' => 'Guard removed successfully.']);
    }
}
