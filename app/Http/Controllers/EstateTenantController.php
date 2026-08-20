<?php

namespace App\Http\Controllers;

use App\Jobs\CancelUserSubscriptionJob;
use App\Jobs\NotifyPttServerJob;
use App\Mail\HouseholdWelcomeMail;
use App\Models\AccountLink;
use App\Models\Channel;
use App\Models\ChannelBillingContact;
use App\Models\ChannelEmployee;
use App\Models\Employee;
use App\Models\EstateMidcycleOptout;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AddressHistoryService;
use App\Services\BillingService;
use App\Services\ChannelBillingService;
use App\Traits\NotifiesNode;
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
        protected AddressHistoryService $addressHistory,
    ) {}

    use NotifiesNode;

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

        $request->merge([
            'phone' => preg_replace('/\s+/', '', (string) $request->input('phone')),
        ]);

        $validated = $request->validate([
            'channel_id'      => 'required|integer',
            'name'            => 'required|string|max:255',
            'email'           => 'required|email|max:255',
            'phone'           => 'required|string|max:15',
            'unit_number'     => 'nullable|string|max:50',
            'safe_cancel_pin' => 'required|string|size:6',
            'duress_pin'      => 'required|string|size:6|different:safe_cancel_pin',
        ]);

        $channel = Channel::whereIn('id', $channelIds)->findOrFail($validated['channel_id']);
        $billingContact = $request->user();

        $existingUser = User::withTrashed()->where('email', $validated['email'])->first();

        if ($existingUser && !$existingUser->trashed()) {
            return response()->json(['message' => 'The email has already been taken.'], 422);
        }

        return DB::transaction(function () use ($validated, $channel, $billingContact, $existingUser) {
            $isClaim = (bool) $existingUser;
            $plainPassword = $isClaim ? null : Str::password(12);

            $userData = [
                'name'                => $validated['name'],
                'email'               => $validated['email'],
                'phone'               => $validated['phone'],
                'occupation'          => 'household',
                'role'                => 'household',
                'address_line_1'      => $billingContact->address_line_1,
                'suburb'              => $billingContact->suburb,
                'latitude'            => $billingContact->latitude,
                'longitude'           => $billingContact->longitude,
                'complex_name'        => $channel->name,
                'unit_number'         => $validated['unit_number'] ?? null,
                'safe_cancel_pin'     => $validated['safe_cancel_pin'],
                'duress_pin'          => $validated['duress_pin'],
                'subscription_status' => 'active',
            ];

            if ($isClaim) {
                // Restoring a previously removed tenant — undo exactly what destroy()
                // set, repoint their existing rows to this estate/channel, never create.
                $existingUser->restore();
                $existingUser->update($userData + [
                    'is_active'        => true,
                    'sos_suspended_at' => null,
                ]);
                $user = $existingUser;

                $employee = Employee::withTrashed()->where('user_id', $user->id)->first();
                $employee->restore();
                $employee->update(['client_id' => $channel->client_id]);

                $pivot = ChannelEmployee::withTrashed()->where('employee_id', $employee->id)->first();
                $pivot->restore();
                $pivot->update(['channel_id' => $channel->id]);

                $subscription = Subscription::where('user_id', $user->id)->first();

                if ($subscription) {
                    $subscription->update([
                        'status'                  => 'active',
                        'ends_at'                 => null,
                        'channel_subscription_id' => null,
                        'cancellation_reason'     => null,
                        'sos_suspended_at'        => null,
                    ]);
                } else {
                    $this->employeeController->createHouseholdSubscription($user, $channel, false);
                }
            } else {
                $user = User::create($userData + ['password' => Hash::make($plainPassword)]);

                $employee = Employee::create([
                    'user_id'   => $user->id,
                    'client_id' => $channel->client_id,
                ]);

                $employee->channels()->attach($channel->id);

                $this->employeeController->createHouseholdSubscription($user, $channel, false);
            }

            $this->addressHistory->record($user, $channel);

            $this->billingService->optInHousehold($user, $channel);

            if ($plainPassword) {
                $this->employeeController->sendHouseholdWelcomeMail(
                    $user, $channel->client_id, $plainPassword, $channel, estateBilled: true,
                );
            }

            return response()->json([
                'message' => $isClaim ? 'Tenant restored and added successfully.' : 'Tenant added successfully. Welcome email sent.',
            ]);
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

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'unit_number' => 'nullable|string|max:50',
        ]);

        $addressChanged = $employee->user->complex_name !== $channel->name
            || $employee->user->unit_number !== ($validated['unit_number'] ?? null);

        $employee->user->update([
            'name'           => $validated['name'],
            'unit_number'    => $validated['unit_number'] ?? null,
            'address_line_1' => $request->user()->address_line_1,
            'suburb'         => $request->user()->suburb,
            'latitude'       => $request->user()->latitude,
            'longitude'      => $request->user()->longitude,
            'complex_name'   => $channel->name,
        ]);

        if ($addressChanged) {
            $this->addressHistory->record($employee->user, $channel);
        }

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

        $subscription = Subscription::where('user_id', $userId)
            ->latest()
            ->first();

        DB::transaction(function () use ($employee, $userId, $subscription) {
            // Log mid-cycle exit for billing, same as ChannelBillingService::optOutHousehold —
            // this tenant had estate coverage for part of the current cycle.
            if ($subscription && $subscription->channel_subscription_id && $subscription->cancellation_reason === 'estate_optin') {
                $channelSubscription = $subscription->channelSubscription;

                if ($channelSubscription) {
                    $channel = $employee->channels()->first();

                    $alreadyPaidForThisCycle = $channelSubscription->paid_at
                        && $subscription->estate_optin_at
                        && $subscription->estate_optin_at->lte($channelSubscription->paid_at);

                    $alreadyLoggedThisCycle = EstateMidcycleOptout::where('user_id', $userId)
                        ->where('channel_subscription_id', $channelSubscription->id)
                        ->exists();

                    if (!$alreadyPaidForThisCycle && !$alreadyLoggedThisCycle) {
                        EstateMidcycleOptout::create([
                            'user_id'                 => $userId,
                            'channel_id'              => $channel->id,
                            'channel_subscription_id' => $channelSubscription->id,
                            'amount_owed'             => BillingService::unitPrice($channel->amount_per_household ?? null),
                            'opted_out_at'            => now(),
                            'billed'                  => false,
                        ]);
                    }
                }
            }

            $this->addressHistory->close($employee->user);

            User::where('id', $userId)
                ->update([
                    'is_active'           => false,
                    'subscription_status' => 'cancelled',
                    'sos_suspended_at'    => now(),
                    'address_line_1'      => null,
                    'suburb'              => null,
                    'latitude'            => null,
                    'longitude'           => null,
                    'complex_name'        => null,
                    'unit_number'         => null,
                ]);

            if ($subscription) {
                $subscription->update([
                    'status'                  => 'cancelled',
                    'ends_at'                 => now(),
                    'channel_subscription_id' => null,
                    'cancellation_reason'     => 'no_coverage_relocation',
                    'sos_suspended_at'        => now(),
                ]);
            }

            // Cut off any accounts linked to this tenant as primary — same cutoff
            // as AccountLinkController@forceUnlink / cancelForUser's Option A loop.
            $activeLinks = AccountLink::where('primary_account_id', $userId)
                ->where('status', 'active')
                ->get();

            foreach ($activeLinks as $link) {
                $linkedUser = $link->linkedAccount;
                $linkedSubscription = $linkedUser?->subscription;

                if ($linkedSubscription) {
                    $linkedSubscription->update([
                        'channel_subscription_id' => null,
                        'cancellation_reason'     => null,
                        'status'                  => 'cancelled',
                        'ends_at'                 => now(),
                        'sos_suspended_at'        => now(),
                    ]);
                }

                if ($linkedUser) {
                    $linkedUser->update([
                        'subscription_status' => 'cancelled',
                        'sos_suspended_at'    => now(),
                        ]);

                    NotifyPttServerJob::dispatch('/payment-failed', [
                        'userId'       => $linkedUser->id,
                        'forceSuspend' => true,
                        'reason'       => 'account_unlinked',
                    ]);
                }

                $link->update(['status' => 'cancelled']);
            }

            User::where('id', $userId)->delete();
            $employee->delete();
            ChannelEmployee::where('employee_id', $employee->id)->delete();
        });

        CancelUserSubscriptionJob::dispatch($userId);

        NotifyPttServerJob::dispatch('/force-disconnect', [
            'userId' => $userId,
            'reason' => 'user_inactive',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant removed successfully!',
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


    public function bulkBilling(Request $request)
    {
        $channelIds = $this->myChannelIds($request);

        $validated = $request->validate([
            'action'          => 'required|in:opt_in,opt_out',
            'employee_ids'    => 'required|array|min:1',
            'employee_ids.*'  => 'integer|exists:employees,id',
        ]);

        $employees = Employee::with('user.subscription', 'channels')
            ->whereIn('id', $validated['employee_ids'])
            ->whereHas('channels', fn($q) => $q->whereIn('channels.id', $channelIds))
            ->get();

        $results = [];

        foreach ($employees as $employee) {
            $channel = $employee->channels->whereIn('id', $channelIds)->first();

            if (!$channel) {
                $results[] = ['id' => $employee->id, 'success' => false, 'message' => 'No matching channel.'];
                continue;
            }

            try {
                if ($validated['action'] === 'opt_in') {
                    $this->billingService->optInHousehold($employee->user, $channel);
                } else {
                    $this->billingService->optOutHousehold($employee->user, $channel, true);
                }
                $results[] = ['id' => $employee->id, 'success' => true];
            } catch (\Exception $e) {
                $results[] = ['id' => $employee->id, 'success' => false, 'message' => $e->getMessage()];
            }
        }

        return response()->json(['success' => true, 'results' => $results]);
    }

}