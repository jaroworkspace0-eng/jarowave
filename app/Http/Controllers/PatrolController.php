<?php

namespace App\Http\Controllers;

use App\Models\Checkpoint;
use App\Models\CheckpointScan;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;

class PatrolController extends Controller
{
    // ── Guard scans a checkpoint QR ───────────────────────────────────────────
    public function scan(Request $request)
    {
        $request->validate([
            'token'      => 'required|string',
            'note'       => 'nullable|string|max:300',
            'scanned_at' => 'nullable|date',
        ]);
 
        $guard = $request->user(); // authenticated guard
 
        // Find the guard's employee record
        $employee = User::where('id', $guard->id)->firstOrFail();
 
        // Find checkpoint by token — must belong to the guard's estate
        $checkpoint = Checkpoint::where('token', $request->token)
            ->where('client_id', $employee->client_id)
            ->where('is_active', true)
            ->first();
 
        if (!$checkpoint) {
            return response()->json([
                'message' => 'Invalid or unrecognised checkpoint.',
            ], 404);
        }
 
        // Log the scan
        $scan = CheckpointScan::create([
            'checkpoint_id' => $checkpoint->id,
            'guard_id'      => $employee->id,
            'note'          => $request->note,
            'scanned_at'    => $request->scanned_at ?? now(),
        ]);
 
        return response()->json([
            'message'         => 'Checkpoint logged.',
            'checkpoint_name' => $checkpoint->name,
            'scanned_at'      => $scan->scanned_at,
        ]);
    }
 
    // ── Guard's own scan history (filterable by date range) ───────────────────
    public function history(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);
 
        $guard    = $request->user();
        $employee = Employee::where('id', $guard->id)->firstOrFail();
 
        $query = CheckpointScan::where('guard_id', $employee->id)
            ->with('checkpoint')
            ->orderBy('scanned_at', 'desc');
 
        if ($request->from) {
            $query->whereDate('scanned_at', '>=', $request->from);
        }
 
        if ($request->to) {
            $query->whereDate('scanned_at', '<=', $request->to);
        }
 
        $scans = $query->paginate(20)->through(function ($scan) {
            return [
                'id'              => $scan->id,
                'checkpoint_name' => $scan->checkpoint->name,
                'token'           => $scan->checkpoint->token,
                'note'            => $scan->note,
                'scanned_at'      => $scan->scanned_at,
            ];
        });
 
        return response()->json(['scans' => $scans]);
    }
}
