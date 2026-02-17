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
     return response()->json([
         'stats' => [
                'channelsCount' => Channel::count(),
                'employeesCount' => Employee::count(),
                'clientsCount' => Client::count(),
                'onlineCount' => User::where('role', 'employee')->where('status', 'online')->count(),
                'offlineCount' => User::where('role', 'employee')->where('status', 'offline')->count(),
            ]
         ]);
   }
}
