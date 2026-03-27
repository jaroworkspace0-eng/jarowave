<?php

namespace App\Http\Controllers;

use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AccountDeletionController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name'   => 'required|string|max:255',
            'email'  => 'required|email',
            'phone'  => 'nullable|string|max:20',
            'reason' => 'nullable|string|max:100',
            'notes'  => 'nullable|string|max:1000',
        ]);

        // Find user by email
        $user = User::where('email', $request->email)->first();

        $deletion = AccountDeletionRequest::create([
            'user_id'              => $user?->id,
            'name'                 => $request->name,
            'email'                => $request->email,
            'phone'                => $request->phone,
            'reason'               => $request->reason,
            'notes'                => $request->notes,
            'status'               => 'pending',
            'requested_at'         => now(),
            'scheduled_deletion_at' => now()->addDays(30),
        ]);

        // Suspend account immediately if user exists
        if ($user) {
            $user->update(['is_active' => 0]);
            $user->tokens()->delete(); // revoke all tokens
        }

        // Send confirmation email
        Mail::raw(
            "Hi {$request->name},\n\nYour account deletion request has been received.\n\nYour account has been suspended and all data will be permanently deleted on: " . now()->addDays(30)->format('d M Y') . "\n\nIf this was a mistake, contact us at privacy@jaroworkspace.com.\n\nEcho Link · JaroWorkspace",
            function ($message) use ($request) {
                $message->to($request->email)
                        ->subject('Account Deletion Request Received — Echo Link');
            }
        );

        return response()->json([
            'message'              => 'Deletion request submitted successfully.',
            'scheduled_deletion_at' => now()->addDays(30)->format('d M Y'),
        ]);
    }

    public function index()
    {
        $requests = AccountDeletionRequest::with('user')
            ->latest()
            ->paginate(20);

        return response()->json($requests);
    }

    public function cancel($id)
    {
        $deletion = AccountDeletionRequest::findOrFail($id);
        $deletion->update([
        'status'            => 'cancelled',
        'processed_at'      => now(),
        'processed_by'      => auth()->id(),
        'processed_by_type' => 'admin',
    ]);

        // Reactivate user
        if ($deletion->user) {
            $deletion->user->update(['is_active' => 1]);
        }

        return response()->json(['message' => 'Deletion request cancelled.']);
    }

    public function destroy($id)
    {
        $deletion = AccountDeletionRequest::findOrFail($id);

        $user = User::find($deletion->user_id);

        if ($user) {
            $user->tokens()->delete();
            $user->update([
                'name'      => 'Deleted User',
                'email'     => 'deleted_' . $user->id . '@deleted.com',
                'phone'     => null,
                'password'  => bcrypt(str()->random(32)),
                'is_active' => 0,
            ]);
            $user->employee?->delete();
        }

        $deletion->update([
            'status'            => 'deleted',
            'processed_at'      => now(),
            'processed_by'      => auth()->id(),
            'processed_by_type' => 'admin',
        ]);

        return response()->json(['message' => 'Account deleted successfully.']);
    }
}