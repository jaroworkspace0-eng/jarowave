<?php

// app/Http/Controllers/SearchController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\User;     // employees
use App\Models\Channel;
use Inertia\Inertia;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');

        $clients = Client::query()
            ->when($search, fn($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
            )
            ->get();

        $employees = User::query()
            ->when($search, fn($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->where('role', "employee")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
            )
            ->get();

        $channels = Channel::query()
            ->when($search, fn($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%")
            )
            ->get();

       return response()->json([ 
        'clients' => $clients, 
        'employees' => $employees, 
        'channels' => $channels, 
    ]);
    }
}

