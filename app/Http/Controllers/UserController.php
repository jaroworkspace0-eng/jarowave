<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        return Inertia::render("Users/Index",[
            "users" => User::all()
        ]);

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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function toggleStatus(User $user)
    {
        $user->update([
            'is_active' => !$user->is_active
        ]);


        // When marking user inactive
         if (!$user->is_active) {
            try {
                Http::timeout(5)
                    ->withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                    ->post(env('PTT_SERVER_URL') . '/force-disconnect', [
                        'userId' => $user->id,
                        'reason' => 'user_inactive',
                    ]);
            } catch (\Exception $e) {
                Log::warning('PTT force-disconnect failed: ' . $e->getMessage());
            }
        }

          return response()->json([
                'success' => true,
                'message' => 'User status updated successfully.',
            ]);

    }
}
