<?php

namespace App\Http\Controllers;

use App\Models\HouseholdSetting;
use Illuminate\Http\Request;

class HouseholdSettingController extends Controller
{
    public function show(Request $request)
    {
        $householdId = $request->user()->id;

        $settings = HouseholdSetting::firstOrCreate(
            ['user_id' => $householdId],
            [
                'auto_accept'      => false,
                'sos_alerts'       => true,
                'all_clear'        => true,
                'appear_in_search' => true,
                'show_suburb'      => true,
                'sound_vibrate'    => true,
            ]
        );

        return response()->json([
            'autoAccept'      => $settings->auto_accept,
            'sosAlerts'       => $settings->sos_alerts,
            'allClear'        => $settings->all_clear,
            'appearInSearch'  => $settings->appear_in_search,
            'showSuburb'      => $settings->show_suburb,
            'soundVibrate'    => $settings->sound_vibrate,
        ]);
    }

    public function update(Request $request)
    {
        $householdId = $request->user()->id;

        // map camelCase from app → snake_case in DB
        $keyMap = [
            'autoAccept'     => 'auto_accept',
            'sosAlerts'      => 'sos_alerts',
            'allClear'       => 'all_clear',
            'appearInSearch' => 'appear_in_search',
            'showSuburb'     => 'show_suburb',
            'soundVibrate'   => 'sound_vibrate',
        ];

        $updates = [];
        foreach ($keyMap as $camel => $snake) {
            if ($request->has($camel)) {
                $updates[$snake] = (bool) $request->input($camel);
            }
        }

        if (empty($updates)) {
            return response()->json(['message' => 'Nothing to update'], 422);
        }

        HouseholdSetting::updateOrCreate(
            ['user_id' => $householdId],
            $updates
        );

        return response()->json(['message' => 'Settings updated']);
    }


    public function getSosAlertsForUser(Request $request, int $userId)
    {
        if ($request->header('X-PTT-Secret') !== env('ASSIGN_SECRET')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $settings = HouseholdSetting::where('user_id', $userId)->first();

        return response()->json([
            'sosAlerts'    => $settings ? (bool) $settings->sos_alerts : true,
            'soundVibrate' => $settings ? (bool) $settings->sound_vibrate : true,
        ]);
    }
}
