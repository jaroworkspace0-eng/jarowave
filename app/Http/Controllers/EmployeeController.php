<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmployeeResource;
use App\Models\Channel;
use App\Models\ChannelEmployee;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    // public function index()
    

    public function index(Request $request)
    {
        // 1. Grab the status from the URL (?status=offline)
        $status = $request->query('status');

        $employees = Employee::with(['channel', 'client', 'user'])
            // 2. Only apply this filter if $status is present
            ->when($status, function ($query, $status) {
                return $query->whereHas('user', function ($q) use ($status) {
                    $q->where('status', $status);
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10)
            // 3. This ensures that when you click "Page 2", the filter stays active
            ->withQueryString();

        // return Inertia::render('employees/index', [
        //     'employees' => $employees,
        //     'filters' => $request->only(['status']), // Optional: pass current filter back to Vue
        // ]);

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
    public function store(Request $request)
    {

        $validated = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|max:250|unique:users,email',
            'phone' => 'required|digits_between:7,15|unique:users,phone',
            'occupation' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        return DB::transaction(function () use ($request, $validated) {

            $user = User::create(array_merge($validated, ['role' => 'employee']));

            $client_id = Channel::where('id', $request->channel_id)->value('client_id');

            if($user) {
                $data = [
                    'user_id' => $user->id,
                    'client_id' => $client_id, // This is your new direct link!
                    'channel_id' => $request->channel_id,
                ];
                $employee = Employee::create($data);

                // 3. Link the Channel(s)
                // This goes into the channel_employee pivot table automatically
                if ($request->has('channel_id')) {
                    $employee->channel()->syncWithoutDetaching($request->channel_id);
                }

                  return response()->json([ 
                    'success' => true, 
                    'message' => 'Employee created and assigned to ' . ($employee->client->name ?? 'client'), 
                    'client' => $employee, 
                ]);

            }

             return response()->json([ 
                    'success' => false, 
                    'message' => 'Failed to create employee.', 
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
    public function edit(Employee $employee)
    {
        return Inertia::render('employee.index', ['employee' => $employee->toArray()]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Employee $employee)
{
    $validated = $request->validate([
        'name' => 'required|string',
        'email' => [
            'required',
            'email',
            'max:250',
            Rule::unique('users', 'email')->ignore($employee->user_id),
        ],
        'phone' => 'required|string|max:15',
        'occupation' => 'required|string',
        'channel_id' => 'required|exists:channels,id',
        'password' => 'nullable|string|min:8', // Make password optional on update
    ]);

    return DB::transaction(function () use ($request, $validated, $employee) {
        // 1. Update the User record
        $userData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'occupation' => $validated['occupation'],
            'phone' => $validated['phone'],
        ];

        // Only update password if a new one is provided
        if (!empty($validated['password'])) {
            $userData['password'] = bcrypt($validated['password']);
        }

        $employee->user->update($userData);

        // 2. Determine client_id from the selected channel (matching your store logic)
        $client_id = Channel::where('id', $request->channel_id)->value('client_id');

        // 3. Update the Employee record
        $employee->update([
            'client_id' => $client_id,
            'phone' => $validated['phone'],
        ]);

        // 4. Sync the Channel (replaces existing links with the new selection)
        if ($request->has('channel_id')) {
            $employee->channel()->sync([$request->channel_id]);
        }

         return response()->json([ 
                    'success' => true, 
                    'message' => 'Employee updated successfully!', 
                    'client' => $employee, 
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
