<?php

namespace App\Http\Controllers;

use App\Mail\HouseholdNoCoverageMail;
use App\Models\Employee;
use App\Models\User;
use App\Services\ChannelBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class UserController extends Controller
{

    private ChannelBillingService $estateBilling;

    public function __construct(ChannelBillingService $estateBilling)
    {
        $this->estateBilling = $estateBilling;
    }

    private function isHouseholdRole(string $role): bool
    {
        return in_array($role, ['household', 'resident']);
    }


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

    public function updateDutyStatus(Request $request)
    {
        $user = auth()->user();
        $request->validate(['is_on_duty' => 'required|boolean']);
        $user->update(['is_on_duty' => $request->is_on_duty]);
        return response()->json(['is_on_duty' => $user->is_on_duty]);
    }

    public function deactivateNoCoverage(User $user)
    {
        if (!$this->isHouseholdRole($user->role)) {
            return response()->json(['message' => 'User is not a household'], 422);
        }

        $employee = Employee::where('user_id', $user->id)->first();

        if (!$employee) {
            return response()->json(['message' => 'No employee record found for this user'], 422);
        }

        $billingOutcome = null;
        $billingNotes   = [];

        return DB::transaction(function () use ($user, $employee, &$billingOutcome, &$billingNotes) {
            $currentChannel = $employee->channels()->first();

            // ── Step 1: opt out of estate billing or cancel individual subscription ──
            if ($currentChannel && $currentChannel->billing_model === 'bulk') {
                try {
                    $this->estateBilling->optOutHousehold($user, $currentChannel, deactivating: true);
                    $billingOutcome = 'opted_out';
                    $billingNotes[] = 'Opted out of estate billing — subscription cancelled.';
                } catch (\Exception $e) {
                    $billingOutcome = 'opt_out_blocked';
                    $billingNotes[] = $e->getMessage();
                    Log::warning('No-coverage deactivation opt-out blocked', [
                        'user_id'    => $user->id,
                        'channel_id' => $currentChannel->id,
                        'reason'     => $e->getMessage(),
                    ]);
                }
            } else {
                $this->estateBilling->cancelIndividualSubscriptionForUser($user, 'no_coverage_relocation');
                $billingOutcome = 'cancelled';
                $billingNotes[] = 'Individual subscription cancelled — no coverage at new address.';
            }

            // ── Step 2: remove channel assignment ────────────────────────────────────
            $employee->channels()->sync([]);
            $employee->update(['client_id' => null]);

            // ── Step 3: deactivate the user ──────────────────────────────────────────
            $user->update([
                'is_active'           => false,
                'subscription_status' => 'cancelled',
            ]);

            // ── Step 4: force-disconnect ──────────────────────────────────────────────
            try {
                Http::timeout(5)
                    ->withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                    ->post(env('PTT_SERVER_URL') . '/force-disconnect', [
                        'userId' => $user->id,
                        'reason' => 'user_inactive',
                    ]);
            } catch (\Exception $e) {
                Log::warning('PTT force-disconnect failed', [
                    'user_id' => $user->id,
                    'reason'  => $e->getMessage(),
                ]);
            }

            // ── Step 5: notify user ───────────────────────────────────────────────────
            try {
                Mail::to($user->email)->send(new HouseholdNoCoverageMail($user));
            } catch (\Exception $e) {
                Log::warning('No-coverage deactivation email failed', [
                    'user_id' => $user->id,
                    'reason'  => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success'         => true,
                'message'         => 'Household deactivated — no Echo Link coverage at new address.',
                'billing_outcome' => $billingOutcome,
                'billing_notes'   => $billingNotes,
            ]);
        });
    }


    public function users()
    {
        abort_if(app()->environment('production'), 403);
        
        return response()->json(
            User::whereIn('role', ['household', 'resident'])
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
        );
    }

    public function updateFcmToken(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => 'required|string',
            'device_id' => 'nullable|string',
        ]);

       $request->user()->update([
            'fcm_token'            => $request->input('fcm_token'),
            'fcm_device_id'        => $request->input('device_id'),
            'fcm_token_updated_at' => now(),
        ]);

        return response()->json([
            'success' => true, 
            'message' => 'FCM token updated successfully.',
            // 'fcm_token' => $request->input('fcm_token'),
            // 'device_id' => $request->input('device_id'),
        ]);
    }


    public function getFcmToken(Request $request, int $userId): JsonResponse
    {

        // Internal only — verify PTT secret
        if ($request->header('X-PTT-Secret') !== env('ASSIGN_SECRET')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'fcm_token' => $user->fcm_token,
            'device_id' => $user->fcm_device_id,
        ]);
    }
}
