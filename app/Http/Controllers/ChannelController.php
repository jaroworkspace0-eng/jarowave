<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\ChannelSubscription;
use App\Models\Client;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ChannelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
   
    public function index()
    {
        $user = Auth::user();

        $query = Channel::with('client.user', 'billingContact.user')
            ->orderBy('created_at', 'desc');

        // If not admin, restrict to channels belonging to the user's client
        if ($user->role !== 'admin') {
            $clientId = $user->client->id; // user → client via clients.user_id
            $query->where('client_id', $clientId);
        }

        $channels = $query->paginate(10);

        return response()->json([
            'channels' => $channels,
        ]);
    }


    // fetch channels for the app
    public function getChannels(Request $request)
    {
        // 1. Get user with relationship (mimicking login)
        // $user = $request->user()->load('employee.channels');

        $user = $request->user()->load(['employee.channel' => function ($query) {
            $query->where('is_active', 1); // Change 'status' to your actual column name (e.g., 'is_active', 1)
        }]);

        // 2. Map the data exactly the same way
        $channels = $user->employee?->channel->map(function($channel) {
            return [
                'id' => $channel->id,
                'name' => $channel->name,
            ];
        }) ?? collect([]);

        // 3. Return the array directly
        return response()->json($channels);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                   => 'required|string|max:255',
            'category'               => 'required|string',
            'channel_type'           => 'required|string',
            'client_id'              => 'required|exists:clients,id',
            'billing_model'          => 'nullable|in:individual,bulk',
            'billing_contact_name'   => 'required_if:billing_model,bulk|string|max:255',
            'billing_contact_email'  => 'required_if:billing_model,bulk|email|unique:users,email',
            'billing_contact_phone'  => 'nullable|string|max:15',
            'amount_per_household'   => 'nullable|numeric|min:1',
            'guard_fixed_amount'     => 'nullable|numeric|min:0',
            'security_pool'          => 'nullable|numeric|min:0',
            'security_percentage'    => 'nullable|numeric|min:0|max:100',
        ]);

        $channel = Channel::create([
            'name'                 => $validated['name'],
            'category'             => $validated['category'],
            'channel_type'         => $validated['channel_type'],
            'client_id'            => $validated['client_id'],
            'billing_model'        => $validated['billing_model'] ?? 'individual',
            'amount_per_household' => $validated['amount_per_household'] ?? 80,
            'guard_fixed_amount'   => $validated['guard_fixed_amount'] ?? 0,
            'security_pool'        => $validated['security_pool'] ?? null,
            'security_percentage'  => $validated['security_percentage'] ?? null,
        ]);

        if (($validated['billing_model'] ?? null) === 'bulk') {
            app(ChannelBillingController::class)->storeBillingContact(
                new Request([
                    'name'  => $validated['billing_contact_name'],
                    'email' => $validated['billing_contact_email'],
                    'phone' => $validated['billing_contact_phone'] ?? null,
                ]),
                $channel
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Channel created successfully!',
            'channel' => $channel,
        ]);
    }

    public function update(Request $request, Channel $channel)
    {
        $validated = $request->validate([
            'name'                   => 'required|string|max:255',
            'category'               => 'required|string',
            'channel_type'           => 'required|string',
            'client_id'              => 'required|exists:clients,id',
            'billing_model'          => 'nullable|in:individual,bulk',
            'billing_contact_name'   => 'required_if:billing_model,bulk|string|max:255',
            'billing_contact_email'  => [
                'required_if:billing_model,bulk',
                'email',
                Rule::unique('users', 'email')->ignore(
                    optional($channel->billingContact?->user)->id
                ),
            ],
            'billing_contact_phone'  => 'nullable|string|max:15',
            'amount_per_household'   => 'nullable|numeric|min:1',
            'guard_fixed_amount'     => 'nullable|numeric|min:0',
            'security_pool'          => 'nullable|numeric|min:0',
            'security_percentage'    => 'nullable|numeric|min:0|max:100',
        ]);

        $channel->update([
            'name'                 => $validated['name'],
            'category'             => $validated['category'],
            'channel_type'         => $validated['channel_type'],
            'client_id'            => $validated['client_id'],
            'billing_model'        => $validated['billing_model'] ?? $channel->billing_model,
            'amount_per_household' => $validated['amount_per_household'] ?? $channel->amount_per_household,
            'guard_fixed_amount'   => $validated['guard_fixed_amount'] ?? $channel->guard_fixed_amount,
            'security_pool'        => $validated['security_pool'] ?? $channel->security_pool,
            'security_percentage'  => $validated['security_percentage'] ?? $channel->security_percentage,
        ]);

        // Update all active or pending subscriptions for this channel with the new amount_per_household
        ChannelSubscription::where('channel_id', $channel->id)
            ->whereIn('status', ['pending', 'active'])
            ->update([
                'amount_per_household' => $validated['amount_per_household'] ?? $channel->amount_per_household,
            ]);

        if (($validated['billing_model'] ?? null) === 'bulk') {
            $existingContact = $channel->billingContact()->where('is_active', true)->first();

            if ($existingContact) {
                app(ChannelBillingController::class)->updateBillingContact(
                    new Request([
                        'name'  => $validated['billing_contact_name'],
                        'email' => $validated['billing_contact_email'],
                        'phone' => $validated['billing_contact_phone'] ?? null,
                    ]),
                    $channel
                );
            } else {
                app(ChannelBillingController::class)->storeBillingContact(
                    new Request([
                        'name'  => $validated['billing_contact_name'],
                        'email' => $validated['billing_contact_email'],
                        'phone' => $validated['billing_contact_phone'] ?? null,
                    ]),
                    $channel
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Channel updated successfully.',
            'channel' => $channel,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
           return Channel::where('is_active', 1)->get();
    }


    /**
     * Lightweight list for pickers (e.g. announcement composer).
     */
    public function list(Request $request)
    {
        $user = $request->user();

        $query = Channel::where('is_active', 1)
            ->with('client.user')
            ->orderBy('name');

        if ($user->role !== 'admin') {
            $clientId = $user->client->id;
            $query->where('client_id', $clientId);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                ->orWhereHas('client.user', function ($q2) use ($search) {
                    $q2->where('name', 'like', "%{$search}%")
                        ->orWhere('organisation_name', 'like', "%{$search}%");
                });
            });
        }

        return response()->json($query->get());
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Channel $channel)
    {
        // If you are using a separate edit page
        return Inertia::render('Channels/Edit', [
            'channel' => $channel->load('client'),
            'clients' => Client::all()
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Channel $channel)
    {
        $channel->delete();

         return response()->json([
            'success' => true,
            'message' => 'Channel deleted successfully.',
            'channel' => $channel,
        ]);

    }

    public function toggleStatus(Channel $channel)
    {
        $channel->update(['is_active' => !$channel->is_active]);

        // If deactivating — kick anyone currently connected to this channel
        if (!$channel->is_active) {
            try {
                Http::timeout(5)
                    ->withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                    ->post(env('PTT_SERVER_URL') . '/force-disconnect-channel', [
                        'channelId' => $channel->id,
                        'reason'    => 'channel_inactive',
                    ]);
            } catch (\Exception $e) {
                Log::warning('PTT channel deactivate failed: ' . $e->getMessage());
            }
        }

        return response()->json(['success' => true, 'message' => 'Channels status updated successfully.', 'is_active' => $channel->is_active]);
    }

public function getUnits(Channel $channel)
{
    $units = $channel->employees()->with('user')->get();

    $formattedUnits = $units->mapWithKeys(function ($unit) {
        if (!$unit->user) return [];

        // 🔑 Use the ID as the key to prevent overwriting
        return [
            $unit->user_id => [ 
                'userId' => $unit->user->id,
                'name'     => $unit->user->name,
                'isOnline'   => $unit->user->status ?? 'offline',
                'lastSeen' => $unit->pivot->last_seen ?? null,
                'role'     => $unit->user->occupation ?? 'Guard',
            ]
        ];
    });

    return response()->json($formattedUnits);
}


   public function assignToUser(Request $request)
    {
        if ($request->header('X-PTT-Secret') !== env('ASSIGN_SECRET')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'user_id'       => 'required|integer|exists:users,id',
            'channel_ids'   => 'required|array',
            'channel_ids.*' => 'integer|exists:channels,id',
        ]);

        $employee = Employee::where('user_id', $request->user_id)
            ->firstOrFail();

        // No sync here — update() already saved to DB.
        // We just return the fresh list for Node to push to the device.
        return response()->json(
            $employee->channels()->select('channels.id', 'channels.name')->get()
        );
    }
}
