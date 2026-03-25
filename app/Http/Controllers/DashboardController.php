<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Channel;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserStatusLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
   

public function index()
{
    $user = auth()->user();
    $isAdmin = $user->role === 'admin';
    $clientId = $isAdmin ? null : Client::where('user_id', $user->id)->value('id');

    $scope = fn($q) => $isAdmin ? $q : $q->where('client_id', $clientId);

    return response()->json(['stats' => [
        'channelsCount'  => $scope(Channel::query())->count(),
        'employeesCount' => $scope(Employee::query())->count(),
        'clientsCount'   => $isAdmin ? Client::count() : 1,
        'onlineCount'    => $scope(Employee::query())->whereHas('user', fn($q) => $q->where('status', 'online'))->count(),
        'offlineCount'   => $scope(Employee::query())->whereHas('user', fn($q) => $q->where('status', 'offline'))->count(),

        // existing
        'employeesPerClient' => Client::withCount(['employees' => fn($q) => $isAdmin ? $q : $q->where('client_id', $clientId)])
            ->get()->map(fn($c) => ['name' => $c->user->name, 'count' => $c->employees_count]),

        'announcementsHistory' => Announcement::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')->orderBy('date')
            ->get(),

        'channelActivity' => $scope(Channel::query())->withCount('employees')
            ->get()->map(fn($c) => ['name' => $c->name, 'members' => $c->employees_count]),

        'onlineHistory' => collect(range(6, 0))->map(function ($daysAgo) use ($isAdmin, $clientId) {
            $date = now()->subDays($daysAgo)->toDateString();
            $baseQuery = UserStatusLog::whereDate('logged_at', $date)
                ->whereHas('user.employee', fn($q) => $isAdmin ? $q : $q->where('client_id', $clientId));
            return [
                'time'    => now()->subDays($daysAgo)->format('D'),
                'online'  => (clone $baseQuery)->where('status', 'online')->count(),
                'offline' => (clone $baseQuery)->where('status', 'offline')->count(),
            ];
        }),

        // NEW: active emergencies
        'activeEmergencies' => \App\Models\EmergencyAlert::where('is_resolved', false)
        ->when(!$isAdmin, fn($q) => $q->where('client_id', $clientId))
        ->count(),

        // NEW: peak hours (0-23) from status logs
        'peakHours' => UserStatusLog::selectRaw('HOUR(logged_at) as hour, COUNT(*) as count')
            ->where('status', 'online')
            ->where('logged_at', '>=', now()->subDays(30))
            ->when(!$isAdmin, fn($q) => $q->whereHas('user.employee', fn($q) => $q->where('client_id', $clientId)))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn($r) => ['hour' => $r->hour . ':00', 'count' => $r->count]),

        // NEW: recent activity feed
        'recentActivity' => UserStatusLog::with(['user.employee.channels'])
            ->when(!$isAdmin, fn($q) => $q->whereHas('user.employee', fn($q) => $q->where('client_id', $clientId)))
            ->latest('logged_at')
            ->take(10)
            ->get()
            ->map(fn($log) => [
                'name'      => $log->user->name,
                'status'    => $log->status,
                'logged_at' => $log->logged_at,
                'channel'   => $log->user->employee?->channels->first()?->name ?? null,
            ]),
    ]]);
}
}
