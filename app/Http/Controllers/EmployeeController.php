<?php

namespace App\Http\Controllers;

use App\Mail\HouseholdWelcomeMail;
use App\Models\Channel;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ChannelBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{

    private ChannelBillingService $estateBilling;

    public function __construct(ChannelBillingService $estateBilling)
    {
        $this->estateBilling = $estateBilling;
    }
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function isHouseholdRole(string $role): bool
    {
        return in_array($role, ['household', 'resident']);
    }

    private function resolveClientId(array $channelIds): ?int
    {
        $firstChannelId = $channelIds[0] ?? null;
        return $firstChannelId
            ? Channel::where('id', $firstChannelId)->value('client_id')
            : null;
    }


    private function baseQuery(Request $request)
    {
        $user   = Auth::user();
        $status = $request->query('status');

        $search = $request->query('search');

        $query = Employee::with(['channels', 'client.user', 'user', 'user.subscription'])
            ->when($status, fn($q) => $q->whereHas('user', fn($u) => $u->where('status', $status)))
            ->when($search, fn($q) => $q->whereHas('user', fn($u) => $u->where('name', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")
                ->orWhere('phone', 'like', "%$search%")
            ))
            ->orderBy('created_at', 'desc');

        if ($user->role !== 'admin') {
            $query->where('client_id', $user->client->id);
        }

        return $query;
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

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $base = $this->baseQuery($request);

        $personnel = (clone $base)
            ->whereHas('user', fn($q) => $q->whereNotIn('occupation', ['household', 'resident']))
            ->paginate(10, ['*'], 'personnel_page')
            ->withQueryString();

        $households = (clone $base)
            ->whereHas('user', fn($q) => $q->whereIn('occupation', ['household', 'resident']))
            ->paginate(10, ['*'], 'household_page')
            ->withQueryString();

        return response()->json([
            'personnel'       => $personnel,
            'households'      => $households,
            'personnel_total' => $personnel->total(),
            'household_total' => $households->total(),
        ]);
    }


    public function householdList(Request $request)
    {
        $query = Employee::with(['user', 'client.user'])
            ->whereHas('user', fn($q) => $q->whereIn('occupation', ['household', 'resident']));

        // Scope to client's own employees if not admin
        if ($request->boolean('scoped')) {
            $clientId = \App\Models\Client::where('user_id', auth()->id())->value('id');
            if ($clientId) $query->where('client_id', $clientId);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', $search)
                ->orWhere('email', 'like', $search);
            });
        }

        return response()->json($query->get());
    }

    public function patrollerList(Request $request)
    {
        $query = Employee::with(['user', 'client.user'])
            ->whereHas('user', fn($q) => $q->whereNotIn('occupation', ['household', 'resident']));

        // Scope to client's own employees if not admin
        if ($request->boolean('scoped')) {
            $clientId = \App\Models\Client::where('user_id', auth()->id())->value('id');
            if ($clientId) $query->where('client_id', $clientId);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', $search)
                ->orWhere('email', 'like', $search);
            });
        }

        return response()->json($query->get());
    }



    // registration for households via admin dashboard
    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => preg_replace('/\s+/', '', $request->phone)]);
        }

        $validated = $request->validate([
            'name'            => 'required|string',
            'email'           => 'required|email|max:250|unique:users,email',
            'phone'           => ['required', 'unique:users,phone', 'min:10', 'max:15', 'regex:/^\+[1-9]\d{1,14}$/'],
            'occupation'      => 'required|string',
            'password'        => 'required|string|min:8',
            // 'channel_ids'     => 'required|array',
             'channel_ids'     => [
                'required',
                'array',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->boolean('is_gate_guard') && count($value) > 1) {
                        $fail('A gate guard can only be assigned to one channel.');
                    }
                },
            ],
            'channel_ids.*'   => 'exists:channels,id',
            'role'            => 'required|string',
            'address_line_1'  => 'required_if:role,household,resident|nullable|string',
            'suburb'          => 'required_if:role,household,resident|nullable|string',
            // 'latitude'        => 'required_if:role,household,resident|nullable|numeric',
            // 'longitude'       => 'required_if:role,household,resident|nullable|numeric',
            'latitude'        => 'nullable|numeric',
            'longitude'       => 'nullable|numeric',
            'complex_name'    => 'nullable|string',
            'access_code'     => 'nullable|string',
            'unit_number'     => 'nullable|string',
            'safe_cancel_pin' => 'nullable|string|size:6',
            'duress_pin'      => 'nullable|string|size:6',
            'is_estate' => 'boolean',
            'is_gate_guard'   => 'boolean',
        ], [
            'phone.regex' => 'The phone number must include a country code starting with +',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $finalRole     = $this->isHouseholdRole($validated['role']) ? $validated['role'] : 'employee';
            $isHousehold   = $this->isHouseholdRole($finalRole);
            $plainPassword = $validated['password'];

            $user = User::create([
                'name'            => $validated['name'],
                'email'           => $validated['email'],
                'phone'           => $validated['phone'],
                'occupation'      => $validated['occupation'],
                'role'            => $finalRole,
                'password'        => bcrypt($plainPassword),
                'address_line_1'  => $isHousehold ? ($validated['address_line_1'] ?? null)  : null,
                'suburb'          => $isHousehold ? ($validated['suburb'] ?? null)           : null,
                'latitude'        => $isHousehold ? ($validated['latitude'] ?? null)         : null,
                'longitude'       => $isHousehold ? ($validated['longitude'] ?? null)        : null,
                'complex_name'    => $isHousehold ? ($validated['complex_name'] ?? null)     : null,
                'access_code'     => $isHousehold ? ($validated['access_code'] ?? null)      : null,
                'unit_number'     => $isHousehold ? ($validated['unit_number'] ?? null)      : null,
                'safe_cancel_pin' => $isHousehold ? ($validated['safe_cancel_pin'] ?? null)  : null,
                'duress_pin'      => $isHousehold ? ($validated['duress_pin'] ?? null)       : null,
                'is_estate' => $isHousehold ? $request->boolean('is_estate', false) : false,
                'is_gate_guard'   => !$isHousehold ? $request->boolean('is_gate_guard', false) : false,
            ]);

            $clientId = $this->resolveClientId($request->channel_ids);

            $employee = Employee::create([
                'user_id'   => $user->id,
                'client_id' => $clientId,
            ]);

            $employee->channels()->sync($request->channel_ids);
            $channel = Channel::find($request->channel_ids[0]);

            if ($isHousehold) {
                $this->createHouseholdSubscription($user, $clientId, $request->boolean('activation_fee_paid', false));
                $this->sendHouseholdWelcomeMail($user, $clientId, $plainPassword, $channel);
            }

            return response()->json([
                'success' => true,
                'message' => ucfirst($finalRole) . ' created successfully.',
                'user'    => $user->load('employee.channels'),
            ]);
        });
    }

    // ── Edit ──────────────────────────────────────────────────────────────────

    public function edit(Employee $employee)
    {
        return response()->json(
            $employee->load('channels', 'user', 'client')
        );
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, Employee $employee)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => preg_replace('/\s+/', '', $request->phone)]);
        }

        // App requests never send address fields — used to guard overwrites
        $fromApp = !$request->has('address_line_1') && !$request->has('suburb');

        $validated = $request->validate([
            'name'                      => 'required|string',
            'email'                     => ['required', 'email', 'max:250', Rule::unique('users', 'email')->ignore($employee->user_id)],
            'phone'                     => ['required', 'min:10', 'max:15', Rule::unique('users', 'phone')->ignore($employee->user_id), 'regex:/^\+[1-9]\d{1,14}$/'],
            'occupation'                => 'required|string',
            'role'                      => 'required|string',
            // 'channel_ids'               => 'array',
            'channel_ids'   => [
                'array',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->boolean('is_gate_guard') && count($value) > 1) {
                        $fail('A gate guard can only be assigned to one channel.');
                    }
                },
            ],
            'channel_ids.*'             => 'integer|exists:channels,id',
            'password'                  => 'nullable|string|min:8',
            'current_password'          => 'nullable|string',
            'new_password'              => 'nullable|string|min:8|confirmed',
            'new_password_confirmation' => 'nullable|string',
            'address_line_1'            => ($fromApp ? 'nullable' : 'required_if:role,household,resident') . '|nullable|string',
            'suburb'                    => ($fromApp ? 'nullable' : 'required_if:role,household,resident') . '|nullable|string',
            // 'latitude'                  => ($fromApp ? 'nullable' : 'required_if:role,household,resident') . '|nullable|numeric',
            // 'longitude'                 => ($fromApp ? 'nullable' : 'required_if:role,household,resident') . '|nullable|numeric',

            'latitude'                  => 'nullable|numeric',
            'longitude'                 => 'nullable|numeric',
            'complex_name'              => 'nullable|string',
            'access_code'               => 'nullable|string',
            'unit_number'               => 'nullable|string',
            'safe_cancel_pin'           => 'nullable|string|size:6',
            'duress_pin'                => 'nullable|string|size:6',
            'is_estate' => 'boolean',
            'is_gate_guard'             => 'boolean',
        ]);

        // Verify current password if user is changing their own password
        if (!empty($validated['new_password'])) {
            if (empty($validated['current_password'])) {
                return response()->json(['message' => 'Current password is required.', 'errors' => ['current_password' => ['Current password is required.']]], 422);
            }
            if (!Hash::check($validated['current_password'], $employee->user->password)) {
                return response()->json(['message' => 'Current password is incorrect.', 'errors' => ['current_password' => ['The current password is incorrect.']]], 422);
            }
        }

        return DB::transaction(function () use ($validated, $employee, $fromApp) {
            $finalRole   = $this->isHouseholdRole($validated['role']) ? $validated['role'] : 'employee';
            $isHousehold = $this->isHouseholdRole($finalRole);

            // $userData = [
            //     'name'       => $validated['name'],
            //     'email'      => $validated['email'],
            //     'phone'      => $validated['phone'],
            //     'occupation' => $validated['occupation'],
            //     'role'       => $finalRole,
            //     'is_gate_guard' => $validated['is_gate_guard'] ?? false,
            // ];

            $userData = [
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'phone'      => $validated['phone'],
            'occupation' => $validated['occupation'],
            'role'       => $finalRole,
            'is_gate_guard' => $isHousehold ? false : ($validated['is_gate_guard'] ?? false),
        ];

            // Only overwrite address/pin fields from dashboard — never from app
            if (!$fromApp) {
                $userData = array_merge($userData, [
                    'address_line_1'  => $isHousehold ? ($validated['address_line_1'] ?? null)  : null,
                    'suburb'          => $isHousehold ? ($validated['suburb'] ?? null)           : null,
                    'latitude'        => $isHousehold ? ($validated['latitude'] ?? null)         : null,
                    'longitude'       => $isHousehold ? ($validated['longitude'] ?? null)        : null,
                    'complex_name'    => $isHousehold ? ($validated['complex_name'] ?? null)     : null,
                    'access_code'     => $isHousehold ? ($validated['access_code'] ?? null)      : null,
                    'unit_number'     => $isHousehold ? ($validated['unit_number'] ?? null)      : null,
                    'safe_cancel_pin' => $isHousehold ? ($validated['safe_cancel_pin'] ?? null)  : null,
                    'duress_pin'      => $isHousehold ? ($validated['duress_pin'] ?? null)       : null,
                    'is_estate' => $isHousehold ? (bool) ($validated['is_estate'] ?? false) : false,
                ]);
            }

            // Password — admin direct reset
            if (!empty($validated['password'])) {
                $userData['password'] = bcrypt($validated['password']);
            }

            // Password — user self-change (current verified above)
            if (!empty($validated['new_password'])) {
                $userData['password'] = bcrypt($validated['new_password']);
            }

            $employee->user->update($userData);

            // Update client_id from first channel if channels provided
            $clientId = $employee->client_id;
            $billingOutcome = null;
            $billingNotes   = [];

            if (!empty($validated['channel_ids'])) {
                $clientId = Channel::where('id', $validated['channel_ids'][0])->value('client_id');
                $employee->update(['client_id' => $clientId]);

                if ($employee->user->subscription) {
                    $employee->user->subscription->update(['client_id' => $clientId]);
                }

                $previousChannelIds = $employee->channels()->pluck('channels.id')->toArray();

                // ── Household relocation billing ────────────────────────────────
                // Must resolve old/new channel BEFORE sync() overwrites the pivot.
                if ($isHousehold) {
                    $oldPrimaryId = $previousChannelIds[0] ?? null;
                    $newPrimaryId = $validated['channel_ids'][0] ?? null;

                    if ($oldPrimaryId && $newPrimaryId && $oldPrimaryId !== $newPrimaryId) {
                        $oldChannel = Channel::find($oldPrimaryId);
                        $newChannel = Channel::find($newPrimaryId);

                        // Step 1: opt out of old estate billing, if applicable
                        if ($oldChannel && $oldChannel->billing_model === 'bulk') {
                            try {
                                $this->estateBilling->optOutHousehold($employee->user, $oldChannel);
                                $billingOutcome = 'opted_out';
                            } catch (\Exception $e) {
                                $billingOutcome = 'opt_out_blocked';
                                $billingNotes[] = $e->getMessage();
                                Log::warning('Relocation opt-out blocked', [
                                    'user_id'    => $employee->user_id,
                                    'channel_id' => $oldChannel->id,
                                    'reason'     => $e->getMessage(),
                                ]);
                            }
                        }

                        // Step 2: opt into new estate billing, if applicable
                        if ($newChannel && $newChannel->billing_model === 'bulk') {
                            try {
                                $this->estateBilling->optInHousehold($employee->user, $newChannel);
                                $billingOutcome = $billingOutcome === 'opted_out' ? 'opted_out_and_opted_in' : 'opted_in';
                            } catch (\Exception $e) {
                                $billingOutcome = $billingOutcome === 'opted_out'
                                    ? 'opted_out_then_opt_in_blocked'
                                    : 'opt_in_blocked';
                                $billingNotes[] = $e->getMessage();
                                Log::warning('Relocation opt-in blocked', [
                                    'user_id'    => $employee->user_id,
                                    'channel_id' => $newChannel->id,
                                    'reason'     => $e->getMessage(),
                                ]);
                            }
                        } elseif ($billingOutcome === 'opted_out') {
                            // Moved to a non-estate channel — flag if no individual sub exists
                            $hasActiveSub = $employee->user->subscription
                                && in_array($employee->user->subscription->status, ['active', 'trialing']);
                            if (!$hasActiveSub) {
                                $billingOutcome = 'opted_out_individual_required';
                            }
                        }
                    }
                }

                $employee->channels()->sync($validated['channel_ids']);
                $removedIds = array_diff($previousChannelIds, $validated['channel_ids']);

                if (!empty($removedIds)) {
                    $this->notifyPttServer('/force-disconnect-if-on-channel', [
                        'userId'            => $employee->user_id,
                        'removedChannelIds' => array_values($removedIds),
                        'reason'            => 'channel_removed',
                    ]);
                }

                $this->notifyPttServer('/assign-channels', [
                    'userId'     => $employee->user_id,
                    'channelIds' => $validated['channel_ids'],
                ]);
            }

            return response()->json([
                'success'         => true,
                'message'         => ucfirst($finalRole) . ' updated successfully!',
                'employee'        => $employee->load('channels', 'user', 'client'),
                'billing_outcome' => $billingOutcome,
                'billing_notes'   => $billingNotes,
            ]);
        });
    }


    // ── Destroy ───────────────────────────────────────────────────────────────

    public function destroy(Employee $employee)
    {
        $userId = $employee->user_id;

        User::where('id', $userId)->delete();
        $employee->delete();

        $this->notifyPttServer('/force-disconnect', [
            'userId' => $userId,
            'reason' => 'user_inactive',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee deleted successfully!',
        ]);
    }

    // EmployeeController.php
    public function updateDutyStatus(Request $request)
    {
        $request->validate(['is_on_duty' => 'required|boolean']);
        $employee = $request->user();
        $employee->update(['is_on_duty' => $request->is_on_duty]);
        return response()->json(['is_on_duty' => $employee->is_on_duty]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function createHouseholdSubscription(User $user, ?int $clientId, bool $activationFeePaid = false): void
    {
        $client  = Client::with('user')->find($clientId);
        $orgType = $client?->user?->organisation_type ?? 'watch';

        $channel = $client?->channels()->first();

        Subscription::create([
            'user_id'              => $user->id,
            'client_id'            => $clientId,
            'client_type'          => $orgType,
            'status'               => 'trialing',
            'plan'               => null,
            'billing_cycle'        => 'monthly',
            // 'price'                => BillingService::UNIT_PRICE,
            'price' => BillingService::unitPrice($channel->amount_per_household ?? null),
            'trial_ends_at'        => now()->addDays(14), // 14-day trial for households
            'merchant_reference'   => 'HH-' . $user->id . '-' . time(),
            'activation_fee_paid'    => $activationFeePaid ?? null,
            'activation_fee_paid_at' => $activationFeePaid ? now() : null,
        ]);
    }

    private function sendHouseholdWelcomeMail(User $user, ?int $clientId, string $plainPassword, ?Channel $channel = null): void
    {
        $client  = Client::with('user')->find($clientId);
        $orgName = $client?->user?->organisation_name
                ?? $client?->user?->name
                ?? 'Echo Link Community';

        Mail::to($user->email)->queue(new HouseholdWelcomeMail(
            user:             $user,
            organisationName: $orgName,
            gateway:          'payfast',
            adminAdded:       true,
            tempPassword:     $plainPassword,
            amountPerHousehold: $channel?->amount_per_household,
            channelName: $channel?->name
        ));
    }

}