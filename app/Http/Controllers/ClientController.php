<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $clients = Client::with(['user'])
            ->orderBy('created_at', 'desc')->paginate(10);

        return response()->json([
            'clients' => $clients
        ]);

    }

    public function clients() {
        // return new ClientResource(Client::all());
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }


    // This method is for public registration for clients, not admin creation
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'phone'             => ['nullable', 'string', 'max:20'],
            'email'             => ['required', 'email', 'unique:users,email'],
            'organisation_name' => ['required', 'string', 'max:255'],
            'organisation_type' => ['required', 'in:watch,estate'],
            'plan'              => ['nullable', 'required_if:organisation_type,estate', 'in:basic,standard,premium'],
            'billing_cycle'     => ['nullable', 'in:monthly,annual'],
            'password'          => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)],
        ]);

        $user = User::create([
            'name'              => $validated['name'],
            'phone'             => $validated['phone'] ?? null,
            'email'             => $validated['email'],
            'password'          => bcrypt($validated['password']),
            'role'              => 'client',
            'organisation_type' => $validated['organisation_type'],
            'organisation_name' => $validated['organisation_name'],
            'plan'              => $validated['plan'] ?? null,
            'billing_cycle'     => $validated['billing_cycle'] ?? 'monthly',
        ]);

        $client = Client::create([
            'user_id' => $user->id,
        ]);

        // Resolve pricing
        $billingCycle = $validated['billing_cycle'] ?? 'monthly';
        $plan         = $validated['plan'] ?? null;
        $price        = $this->resolvePrice($plan, $billingCycle);

        Subscription::create([
            'client_id'            => $client->id,
            'plan'                 => $plan,
            'billing_cycle'        => $billingCycle,
            'status'               => 'trialing',
            'price'                => $price['discounted'] / 100, // convert cents to dollars
            'original_price'       => $price['original'] / 100, // convert cents to dollars
            'discount_amount'      => $price['discount_amount'] / 100, // convert cents to dollars,
            'discount_percentage'  => $price['discount_percentage'],
            'trial_ends_at'        => now()->addDays(14),
            'current_period_start' => now(),
            'current_period_end'   => $billingCycle === 'annual' ? now()->addYear() : now()->addMonth(),
        ]);

        // Issue token — same shape as your login response
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ], 201);
    }

    // Helper method to resolve pricing based on plan and billing cycle
    private function resolvePrice(?string $plan, string $billingCycle): array
    {
        $monthlyPrices = [
            'basic'    => 49900,
            'standard' => 99900,
            'premium'  => 199900,
        ];

        if (!$plan) {
            return ['original' => 0, 'discounted' => 0, 'discount_amount' => 0, 'discount_percentage' => 0];
        }

        $monthly = $monthlyPrices[$plan];

        if ($billingCycle === 'annual') {
            $discounted     = (int) round($monthly * 0.83 * 12);
            $original       = $monthly * 12;
            $discountAmount = $original - $discounted;
            $discountPct    = 17;
        } else {
            $discounted     = $monthly;
            $original       = $monthly;
            $discountAmount = 0;
            $discountPct    = 0;
        }

        return [
            'original'        => $original,
            'discounted'      => $discounted,
            'discount_amount' => $discountAmount,
            'discount_percentage' => $discountPct,
        ];
    }

    // This method is for admin creation of clients, not public registration
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'email'             => 'required|email|unique:users,email',
            'phone'             => 'required|digits_between:7,15|unique:users,phone',
            'address'           => 'nullable|string',
            'password'          => 'required|string|min:8',
            'organisation_type' => 'required|in:watch,estate',
            'organisation_name' => 'required|string|max:255',
            'plan'              => 'nullable|required_if:organisation_type,estate|in:basic,standard,premium',
            'billing_cycle'     => 'nullable|in:monthly,annual',
        ]);

        $user = User::create([
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'role'              => 'client',
            'address_line_1'    => $validated['address'] ?? null,
            'phone'             => $validated['phone'],
            'password'          => bcrypt($validated['password']),
            'organisation_type' => $validated['organisation_type'],
            'organisation_name' => $validated['organisation_name'],
            'plan'              => $validated['plan'] ?? null,
            'billing_cycle'     => $validated['billing_cycle'] ?? 'monthly',
        ]);

        $client = Client::create([
            'user_id' => $user->id,
        ]);


        // Resolve pricing
        $billingCycle = $validated['billing_cycle'] ?? 'monthly';
        $plan         = $validated['plan'] ?? null;
        $price        = $this->resolvePrice($plan, $billingCycle);

        Subscription::create([
            'client_id'            => $client->id,
            'plan'                 => $plan,
            'billing_cycle'        => $billingCycle,
            'status'               => 'trialing',
            'price'                => $price['discounted'] / 100, // convert cents to dollars
            'original_price'       => $price['original'] / 100, // convert cents to dollars
            'discount_amount'      => $price['discount_amount'] / 100, // convert cents to dollars,
            'discount_percentage'  => $price['discount_percentage'],
            'trial_ends_at'        => now()->addDays(14),
            'current_period_start' => now(),
            'current_period_end'   => $billingCycle === 'annual' ? now()->addYear() : now()->addMonth(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Client created successfully!',
            'client'  => $user,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = auth()->user();

        if ($user->role === 'admin') {
            // Admins see all active clients with their user relation
            $clients = Client::with('user')
                ->whereHas('user', function ($query) {
                    $query->where('is_active', 1);
                })
                ->get();
        } else {
            // Non-admins only see their own client record(s)
            $clients = Client::with('user')
                ->where('user_id', $user->id)
                ->whereHas('user', function ($query) {
                    $query->where('is_active', 1);
                })
                ->get();
        }

        return response()->json($clients);
    }




    /**
     * Show the form for editing the specified resource.
     */
   public function edit(Client $client)
    {
        $client->load('user');

        return Inertia::render('users.index', [
            'user' => $client->user->toArray(),
            'client' => $client->only(['id','is_active','created_at','updated_at']),
        ]);
    }


    /**
     * Update the specified resource in storage.
     */
   public function update(Request $request, Client $client)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => [
            'required',
            'email',
            'max:250',
            Rule::unique('users', 'email')->ignore($client->user_id),
        ],
        'phone' => 'required|numeric',
        'address' => 'nullable|string',
        'password' => 'nullable|string|min:8', // optional password
    ]);

    $user = User::findOrFail($client->user_id);

    $updateData = [
        'name' => $validated['name'],
        'email' => $validated['email'],
        'phone' => $validated['phone'],
        'address_line_1' => $validated['address'] ?? null,
    ];

    // Only update password if provided
    if (!empty($validated['password'])) {
        $updateData['password'] = Hash::make($validated['password']);
    }

    $user->update($updateData);

    return response()->json([
        'success' => true,
        'message' => 'Client updated successfully!',
        'client' => $client->load('user'),
    ]);
}


    /**
     * Remove the specified resource from storage.
     */
  public function destroy($id)
    {
        $client = Client::find($id);

        if (! $client) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found. ' . $id,
            ], 404);
        }

        DB::transaction(function () use ($client) {
            $client->delete();
            $client->user()->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Client and linked user deleted successfully!',
        ]);
    }



    public function toggleStatus(Client $client)
    {
        // $client->update(['is_active' => !$client->is_active]);
        $client->user->update(['is_active' => !$client->user->is_active]);


        if (!$client->is_active) {
            // Fetch all user IDs under this client
            $userIds = Employee::where('client_id', $client->id)
                ->pluck('user_id')
                ->toArray();

            if (!empty($userIds)) {
                try {
                    Http::timeout(5)
                        ->withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                        ->post(env('PTT_SERVER_URL') . '/force-disconnect-client', [
                            'userIds' => $userIds,
                            'reason'  => 'client_inactive',
                        ]);
                } catch (\Exception $e) {
                    Log::warning('PTT force-disconnect-client failed: ' . $e->getMessage());
                }
            }
        }

        return response()->json(['success' => true, 'message' => 'Client status updated', 'is_active' => $client->is_active]);
    }

}
