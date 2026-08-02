<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGateGuardDashboardAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user->role === 'employee'
                && $user->is_gate_guard
                && $user->employee?->has_dashboard_access,
            403
        );

        return $next($request);
    }
}