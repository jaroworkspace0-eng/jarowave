<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EstateAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Route into api.php (Sanctum), per project convention — web.php stays a
 * bare Inertia closure route with no props.
 *
 * Channel scoping mirrors the EmergencyAlertController::list() fix:
 * estate_billing users are resolved via channel_billing_contacts
 * (user_id, is_active), NOT Employee::ch(). Admin sees any channel_id
 * passed in.
 */
class EstateAnalyticsController extends Controller
{
    public function show(Request $request, EstateAnalyticsService $analytics)
    {
        $user = $request->user();

        $channelId = $this->resolveChannelId($request, $user);

        abort_if(!$channelId, 403, 'No channel access.');

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : now()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        return response()->json(
            $analytics->summary($channelId, $from, $to)
        );
    }

    private function resolveChannelId(Request $request, $user): ?int
    {
        if ($user->role === 'admin') {
            return $request->integer('channel_id') ?: null;
        }

        // estate_billing: only channels they're an active billing contact for
        $channelIds = DB::table('channel_billing_contacts')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('channel_id');

        $requested = $request->integer('channel_id');

        if ($requested) {
            return $channelIds->contains($requested) ? $requested : null;
        }

        return $channelIds->first();
    }
}