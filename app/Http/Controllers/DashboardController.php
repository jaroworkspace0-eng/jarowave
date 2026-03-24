<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
   
  public function index()
{
    $user = auth()->user();

    if ($user->role === 'admin') {
        $stats = [
            'channelsCount'  => Channel::count(),
            'employeesCount' => Employee::count(),
            'clientsCount'   => Client::count(),
            'onlineCount'    => User::where('role', 'employee')->where('status', 'online')->count(),
            'offlineCount'   => User::where('role', 'employee')->where('status', 'offline')->count(),
        ];
    } else {
        $client = Client::where('user_id', $user->id)->first();
        $clientId = $client?->id;

        $stats = [
            'channelsCount'  => Channel::where('client_id', $clientId)->count(),
            'employeesCount' => Employee::where('client_id', $clientId)->count(),
            'clientsCount'   => 1,
            'onlineCount'    => User::whereHas('employee', fn($q) => $q->where('client_id', $clientId))
                                    ->where('role', 'employee')
                                    ->where('status', 'online')
                                    ->count(),
            'offlineCount'   => User::whereHas('employee', fn($q) => $q->where('client_id', $clientId))
                                    ->where('role', 'employee')
                                    ->where('status', 'offline')
                                    ->count(),
        ];
    }

    return response()->json(['stats' => $stats]);
}
}
