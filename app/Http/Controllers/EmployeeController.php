<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmployeeResource;
use App\Models\Channel;
use App\Models\ChannelEmployee;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    // public function index()
    

    // public function index(Request $request)
    // {
    //     // 1. Grab the status from the URL (?status=offline)
    //     $status = $request->query('status');

    //     $employees = Employee::with(['channel', 'client', 'user'])
    //         // 2. Only apply this filter if $status is present
    //         ->when($status, function ($query, $status) {
    //             return $query->whereHas('user', function ($q) use ($status) {
    //                 $q->where('status', $status);
    //             });
    //         })
    //         ->orderBy('created_at', 'desc')
    //         ->paginate(10)
    //         ->withQueryString();

    //     return response()->json([
    //         'employees' => $employees,
    //         'filters' => $request->only('status'),
    //     ]);
    // }



    public function index(Request $request)
{
    $status = $request->query('status');

    $employees = Employee::with(['channels', 'client', 'user']) // <-- plural
        ->when($status, function ($query, $status) {
            return $query->whereHas('user', function ($q) use ($status) {
                $q->where('status', $status);
            });
        })
        ->orderBy('created_at', 'desc')
        ->paginate(10)
        ->withQueryString();

    return response()->json([
        'employees' => $employees,
        'filters' => $request->only('status'),
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
    // public function store(Request $request)
    // {

    //     $validated = $request->validate([
    //         'name' => 'required|string',
    //         'email' => 'required|email|max:250|unique:users,email',
    //         'phone' => 'required|digits_between:7,15|unique:users,phone',
    //         'occupation' => 'required|string',
    //         'password' => 'required|string|min:8',
    //     ]);

    //     return DB::transaction(function () use ($request, $validated) {

    //         $user = User::create(array_merge($validated, ['role' => 'employee']));

    //         $client_id = Channel::where('id', $request->channel_id)->value('client_id');

    //         if($user) {
    //             $data = [
    //                 'user_id' => $user->id,
    //                 'client_id' => $client_id, // This is your new direct link!
    //                 'channel_id' => $request->channel_id,
    //             ];
    //             $employee = Employee::create($data);

    //             // 3. Link the Channel(s)
    //             // This goes into the channel_employee pivot table automatically
    //             if ($request->has('channel_id')) {
    //                 $employee->channel()->syncWithoutDetaching($request->channel_id);
    //             }

    //               return response()->json([ 
    //                 'success' => true, 
    //                 'message' => 'Employee created and assigned to ' . ($employee->client->name ?? 'client'), 
    //                 'client' => $employee, 
    //             ]);

    //         }

    //          return response()->json([ 
    //                 'success' => false, 
    //                 'message' => 'Failed to create employee.', 
    //             ]);

    //     });

    // }

public function store(Request $request)
{
    if ($request->has('phone')) {
        $request->merge([
            'phone' => preg_replace('/\s+/', '', $request->phone),
        ]);
    }
    $validated = $request->validate([
        'name'           => 'required|string',
        'email'          => 'required|email|max:250|unique:users,email',
        'phone' => [
            'required',
            'unique:users,phone',
            'min:10',
            'max:15',
            'regex:/^\+[1-9]\d{1,14}$/' // Validates '+' followed by 2 to 15 digits
        ],
        'occupation'     => 'required|string',
        'password'       => 'required|string|min:8',
        'channel_ids'    => 'required|array',
        'channel_ids.*'  => 'exists:channels,id',
        
        // Role is strictly required
        'role'           => 'required|string', 

        // Address fields required ONLY for household/resident
        'address_line_1' => 'required_if:role,household,resident|nullable|string',
        'suburb'         => 'required_if:role,household,resident|nullable|string',
        'latitude'       => 'required_if:role,household,resident|nullable|numeric',
        'longitude'      => 'required_if:role,household,resident|nullable|numeric',
        'complex_name'   => 'nullable|string',
        'access_code'    => 'nullable|string',

        // security code
        'safe_cancel_pin' => 'nullable|string|size:6',
        'duress_pin'      => 'nullable|string|size:6',
    ], [
            // Custom error message for the regex
            'phone.regex' => 'The phone number must include a country code starting with +',
        ]);


    return DB::transaction(function () use ($validated, $request) {
        // Enforce fallback: If not household/resident, it MUST be employee
        $finalRole = in_array($validated['role'], ['household', 'resident']) 
                     ? $validated['role'] 
                     : 'employee';

        // 1. Create the user
        $user = User::create(array_merge($validated, [
            'role'           => $finalRole,
            'password'       => bcrypt($validated['password']),
            // Nullify address fields if role is employee
            'address_line_1' => ($finalRole !== 'employee') ? $validated['address_line_1'] : null,
            'suburb'         => ($finalRole !== 'employee') ? $validated['suburb'] : null,
            'latitude'       => ($finalRole !== 'employee') ? $validated['latitude'] : null,
            'longitude'      => ($finalRole !== 'employee') ? $validated['longitude'] : null,
            'complex_name'   => ($finalRole !== 'employee') ? $validated['complex_name'] : null,
            'access_code'    => ($finalRole !== 'employee') ? $validated['access_code'] : null,

            // security code
            'safe_cancel_pin' => ($finalRole !== 'employee') ? ($validated['safe_cancel_pin'] ?? null) : null,
            'duress_pin'      => ($finalRole !== 'employee') ? ($validated['duress_pin'] ?? null) : null,
        ]));

        // 2. Derive client_id from the first channel
        $firstChannelId = $request->channel_ids[0] ?? null;
        $client_id = $firstChannelId
            ? Channel::where('id', $firstChannelId)->value('client_id')
            : null;

        // 3. Create employee record
        $employee = Employee::create([
            'user_id'   => $user->id,
            'client_id' => $client_id,
        ]);

        // 4. Sync multiple channels
        $employee->channel()->sync($request->channel_ids);

        return response()->json([
            'success'  => true,
            'message'  => ucfirst($finalRole) . ' created successfully.',
            'user'     => $user->load('employee.channel'),
        ]);
    });
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
    // public function edit(Employee $employee)
    // {
    //     return Inertia::render('employee.index', ['employee' => $employee->toArray()]);
    // }

    public function edit(Employee $employee)
    {
        $employee->load('channels', 'user', 'client');

        return response()->json($employee);
    }


    /**
     * Update the specified resource in storage.
     */

public function update(Request $request, Employee $employee)
{
    if ($request->has('phone')) {
        $request->merge([
            'phone' => preg_replace('/\s+/', '', $request->phone),
        ]);
    }
 
    // Detect source: app requests never send address fields
    $fromApp = !$request->has('address_line_1') && !$request->has('suburb');
 
    $validated = $request->validate([
        'name' => 'required|string',
        'email' => [
            'required',
            'email',
            'max:250',
            Rule::unique('users', 'email')->ignore($employee->user_id, 'id'),
        ],
        'phone' => [
            'required',
            'min:10',
            'max:15',
            Rule::unique('users', 'phone')->ignore($employee->user_id, 'id'),
            'regex:/^\+[1-9]\d{1,14}$/',
        ],
        'occupation'   => 'required|string',
        'role'         => 'required|string',
        'channel_ids'  => 'array',
        'channel_ids.*'=> 'integer|exists:channels,id',
 
        // ── Dashboard: admin resets password directly ──
        'password'                  => 'nullable|string|min:8',
 
        // ── App: user changes own password ──
        'current_password'          => 'nullable|string',
        'new_password'              => 'nullable|string|min:8|confirmed',
        'new_password_confirmation' => 'nullable|string',
 
        // Address fields — only required when coming from dashboard
        'address_line_1' => ($fromApp ? 'nullable' : 'required_if:role,household,resident') . '|nullable|string',
        'suburb'         => ($fromApp ? 'nullable' : 'required_if:role,household,resident') . '|nullable|string',
        'latitude'       => ($fromApp ? 'nullable' : 'required_if:role,household,resident') . '|nullable|numeric',
        'longitude'      => ($fromApp ? 'nullable' : 'required_if:role,household,resident') . '|nullable|numeric',
        'complex_name'   => 'nullable|string',
        'access_code'    => 'nullable|string',

        // security code
        'safe_cancel_pin' => 'nullable|string|size:6',
        'duress_pin'      => 'nullable|string|size:6',
    ]);
 
    // ── App: verify current password before allowing change ──
    if (!empty($validated['new_password'])) {
        if (empty($validated['current_password'])) {
            return response()->json([
                'message' => 'Current password is required.',
                'errors'  => ['current_password' => ['Current password is required.']],
            ], 422);
        }
        if (!Hash::check($validated['current_password'], $employee->user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
                'errors'  => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }
    }
 
    return DB::transaction(function () use ($validated, $employee, $fromApp) {
 
        $finalRole = in_array($validated['role'], ['household', 'resident'])
                     ? $validated['role']
                     : 'employee';
 
        $userData = [
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'phone'      => $validated['phone'],
            'occupation' => $validated['occupation'],
            'role'       => $finalRole,
        ];
 
        // Only update address fields when request comes from dashboard
        // App requests must NEVER touch address data — it would wipe them
        if (!$fromApp) {
            $userData['address_line_1'] = ($finalRole !== 'employee') ? ($validated['address_line_1'] ?? null) : null;
            $userData['suburb']         = ($finalRole !== 'employee') ? ($validated['suburb'] ?? null) : null;
            $userData['complex_name']   = ($finalRole !== 'employee') ? ($validated['complex_name'] ?? null) : null;
            $userData['access_code']    = ($finalRole !== 'employee') ? ($validated['access_code'] ?? null) : null;
            $userData['latitude']       = ($finalRole !== 'employee') ? ($validated['latitude'] ?? null) : null;
            $userData['longitude']      = ($finalRole !== 'employee') ? ($validated['longitude'] ?? null) : null;

            // security code
            $userData['safe_cancel_pin'] = ($finalRole !== 'employee') ? ($validated['safe_cancel_pin'] ?? null) : null;
            $userData['duress_pin']      = ($finalRole !== 'employee') ? ($validated['duress_pin'] ?? null) : null;
        }
 
        // ── Dashboard: admin sets password directly ──
        if (!empty($validated['password'])) {
            $userData['password'] = bcrypt($validated['password']);
        }
 
        // ── App: user changes own password (current verified above) ──
        if (!empty($validated['new_password'])) {
            $userData['password'] = bcrypt($validated['new_password']);
        }
 
        $employee->user->update($userData);
 
        $client_id = $employee->client_id;
        if (!empty($validated['channel_ids'])) {
            $client_id = Channel::where('id', $validated['channel_ids'][0])->value('client_id');
        }
 
        $employee->update(['client_id' => $client_id]);
 
        if (isset($validated['channel_ids'])) {
            // Get the IDs the user currently has BEFORE sync
            $previousChannelIds = $employee->channel()->pluck('channels.id')->toArray();

            $employee->channel()->sync($validated['channel_ids']);

            // Check if their current connected channel was removed
            $removedIds = array_diff($previousChannelIds, $validated['channel_ids']);

            if (!empty($removedIds)) {
                // At least one channel was removed — notify Node to check if
                // the user is currently connected to one of the removed channels.
                // Node will force-disconnect them if so.
                try {
                    \Illuminate\Support\Facades\Http::timeout(5)
                        ->withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                        ->post(env('PTT_SERVER_URL') . '/force-disconnect-if-on-channel', [
                            'userId'            => $employee->user_id,
                            'removedChannelIds' => array_values($removedIds),
                            'reason'            => 'channel_removed',
                        ]);
                } catch (\Exception $e) {
                    Log::warning('PTT channel removal notify failed: ' . $e->getMessage());
                }
            }

            // Always notify Node to push updated channel list to device
            try {
                \Illuminate\Support\Facades\Http::timeout(5)
                    ->withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                    ->post(env('PTT_SERVER_URL') . '/assign-channels', [
                        'userId'     => $employee->user_id,
                        'channelIds' => $validated['channel_ids'],
                    ]);
            } catch (\Exception $e) {
                Log::warning('PTT server notify failed: ' . $e->getMessage());
            }
        }
 
        $employee->load('channel', 'user', 'client');
 
        return response()->json([
            'success'  => true,
            'message'  => ucfirst($finalRole) . ' updated successfully!',
            'employee' => $employee,
        ]);
    });
}
 


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Employee $employee)
    {
        $user_id = $employee->user_id;
        // First, delete the associated User record
        User::where('id', $user_id)->delete();
        $employee->delete();

         return response()->json([ 
                    'success' => true, 
                    'message' => 'Employee deleted successfully!', 
                ]);
                
    }
}
