<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
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

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // return response()->json($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|digits_between:7,15|unique:users,phone',
            'address' => 'nullable|string',
            'password' => 'required|string|min:8'
        ]);

       $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => 'client',
            'address_line_1' => $request->address,
            'phone' => $request->phone,
            'password' => bcrypt($validated['password']),
        ]);

        Client::create([
            'user_id' => $user->id,
        ]);

        // $client = Client::create($validated);

          return response()->json([ 
            'success' => true, 
            'message' => 'Client created successfully!', 
            'client' => $user, 
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
