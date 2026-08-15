<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AddressController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Address', [
            'address' => [
                'address_line_1' => $user->address_line_1,
                'suburb' => $user->suburb,
                'access_code' => $user->access_code,
                'latitude' => $user->latitude,
                'longitude' => $user->longitude,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'address_line_1' => 'required|string',
            'suburb' => 'required|string',
            'access_code' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $request->user()->update($validated);

        return back();
    }
}