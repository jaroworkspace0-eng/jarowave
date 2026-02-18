<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $clients = Client::orderBy('created_at', 'desc')->paginate(10);

        return response()->json([
            'clients' => $clients
        ]);

    }

    public function clients() {
        dd('testing');
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
            'email' => 'required|email|unique:clients,email',
            'phone' => 'required|digits_between:7,15|unique:clients,phone',
            'address' => 'nullable|string',
        ]);

        $client = Client::create($validated);

          return response()->json([ 
            'success' => true, 
            'message' => 'Client created successfully!', 
            'client' => $client, 
        ]);

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        return Client::where('is_active', 1)->get();
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Client $client)
    {
        return Inertia::render('users.index', ['user' => $client->toArray()]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
             'email'      => [
            'required',
            'email',
            'max:250',
            Rule::unique('clients', 'email')->ignore($client->id),
        ],
            'phone' => 'required|numeric',
            'address' => 'nullable|string',
        ]);

        $client->update($validated);

        return response()->json([ 
            'success' => true, 
            'message' => 'Client updated successfully!', 
            'client' => $client, 
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

        $client->delete();

        return response()->json([
            'success' => true,
            'message' => 'Client deleted successfully!',
            'client' => $client,
        ]);
    }


public function toggleStatus(Client $client)
{
    $client->update([
        'is_active' => !$client->is_active,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Client status updated successfully.',
        'client' => $client,
    ]);
}

}
