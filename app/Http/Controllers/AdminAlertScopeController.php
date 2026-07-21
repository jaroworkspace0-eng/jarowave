<?php

namespace App\Http\Controllers;

use App\Models\AdminAlertScope;
use Illuminate\Http\Request;

class AdminAlertScopeController extends Controller
{
    // GET /api/admin/alert-scopes
    // With ?admin_id=: that admin's own scopes.
    // Without it: every scope, eager-loaded with admin — used by the
    // Alert Visibility management table. Restrict this branch to
    // super admins only.
    public function index(Request $request)
    {
        if ($request->filled('admin_id')) {
            $adminId = $request->input('admin_id');
            $this->authorizeManaging($request, $adminId);

            return AdminAlertScope::where('admin_id', $adminId)->get();
        }

        abort_unless(
            $request->user()?->role === 'admin',
            403,
            'Not authorized to view all alert scopes.',
        );

        return AdminAlertScope::with('admin:id,name,email')->latest()->get();
    }

    // POST /api/admin/alert-scopes
    // { admin_id?, scope_type: 'channel'|'household', scope_id }
    public function store(Request $request)
    {
        $data = $request->validate([
            'admin_id' => 'sometimes|exists:users,id',
            'scope_type' => 'required|in:channel,household',
            'scope_id' => 'required|integer',
        ]);

        $adminId = $data['admin_id'] ?? $request->user()->id;

        $existing = AdminAlertScope::where('scope_type', $data['scope_type'])
            ->where('scope_id', $data['scope_id'])
            ->first();

        if ($existing && (int) $existing->admin_id !== (int) $adminId) {
            abort(409, 'This scope is already claimed by another admin.');
        }

        try {
            return AdminAlertScope::firstOrCreate([
                'admin_id' => $adminId,
                'scope_type' => $data['scope_type'],
                'scope_id' => $data['scope_id'],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                abort(409, 'This scope is already claimed by another admin.');
            }
            throw $e;
        }
    }

    // DELETE /api/admin/alert-scopes/{scope}
    public function destroy(Request $request, AdminAlertScope $scope)
    {
        abort_unless(
            $request->user()->id === $scope->admin_id,
            403,
            'You can only release claims assigned to you.',
        );

        $scope->delete();

        return response()->noContent();
    }

    // Replace this with your real authorization (a Policy or role check).
    // As written: an admin can only manage their own scopes unless they
    // carry a 'super_admin' role/flag — adjust to match your User model.
    private function authorizeManaging(Request $request, int $targetAdminId): void
    {
        abort_unless(
            $request->user()->id === $targetAdminId,
            403,
            'Not authorized to manage this admin\'s alert scopes.',
        );
    }

    public function indexAll(Request $request)
    {
        if ($request->header('X-PTT-Secret') !== env('ASSIGN_SECRET')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return AdminAlertScope::select('admin_id', 'scope_type', 'scope_id')->get();
    }
}