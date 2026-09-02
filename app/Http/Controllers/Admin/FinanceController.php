<?php
// Target: app/Http/Controllers/Admin/FinanceController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountLink;
use App\Models\SubscriptionPayment;
use App\Models\ChannelSubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FinanceController extends Controller
{
    /**
     * Shared date-range resolver. Accepts ?from=YYYY-MM-DD&to=YYYY-MM-DD,
     * defaults to the last 30 days if not provided.
     */
    private function resolveRange(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : $to->copy()->subDays(30)->startOfDay();

        return [$from, $to];
    }

    /**
     * Normalized union of individual + estate-bulk payments.
     * ASSUMPTION: both tables have columns id, amount, status, payment_method,
     * paid_at, created_at. Adjust column names below to match your actual schema.
     */
    private function unifiedQuery($from, $to)
    {
        $individual = SubscriptionPayment::query()
            ->select([
                'id',
                DB::raw("'individual' as source"),
                'amount',
                'status',
                'payment_method',
                'paid_at',
                'created_at',
                'covered_by_payment_id',
            ])
            ->whereBetween('created_at', [$from, $to]);

        $estate = ChannelSubscriptionPayment::query()
            ->select([
                'id',
                DB::raw("'estate' as source"),
                'amount',
                'status',
                'payment_method',
                'paid_at',
                'created_at',
                DB::raw('NULL as covered_by_payment_id'), // estate payments are never "covered"
            ])
            ->whereBetween('created_at', [$from, $to]);

        return $individual->unionAll($estate);
    }

    /**
     * Attach household_name / linked_accounts / proof_of_payment_url onto a
     * page of union rows by re-hydrating the underlying Eloquent models.
     *
     * Individual payments: SubscriptionPayment -> subscription -> user.
     * Subscription has both `client_id` and `user_id` — client is the Area
     * Partner/referrer (Client has partner_type/revenue_share_percentage,
     * nothing person-like), while `user` is the actual subscriber, so that's
     * what household_name should read. Falls back to the payment's own
     * `payer_name` if the subscription/user chain is missing.
     *
     * Estate/bulk payments: ChannelSubscriptionPayment -> channelSubscription
     * -> channel. household_name is the Channel's own `name` (the estate),
     * since one ChannelSubscriptionPayment is a single bulk payment covering
     * the whole estate, not one household. linked_accounts is the list of
     * individual Subscriptions opted into that channel_subscription_id
     * (Subscription.channel_subscription_id), each named via its own `user`
     * (same reasoning as above — not `client`).
     */
    private function hydrateHouseholdInfo($rows)
    {
        $individualIds = collect($rows)->where('source', 'individual')->pluck('id');
        $estateIds = collect($rows)->where('source', 'estate')->pluck('id');

        $individualPayments = SubscriptionPayment::query()
            ->with('subscription.user')
            ->whereIn('id', $individualIds)
            ->get()
            ->keyBy('id');

        $estatePayments = ChannelSubscriptionPayment::query()
            ->with(['channelSubscription.channel', 'channelSubscription.subscriptions.user'])
            ->whereIn('id', $estateIds)
            ->get()
            ->keyBy('id');

        // Collect every user id we'll need account-link info for, in one pass.
        $allUserIds = collect();
        foreach ($individualPayments as $p) {
            $allUserIds->push($p->subscription?->user_id);
        }
        foreach ($estatePayments as $p) {
            foreach ($p->channelSubscription?->subscriptions ?? [] as $s) {
                $allUserIds->push($s->user_id);
            }
        }
        $links = $this->resolveAccountLinks($allUserIds);

        $buildAccountLink = function (?int $userId) use ($links) {
            if (!$userId) return null;

            if ($links['byPrimary']->has($userId)) {
                return [
                    'is_primary' => true,
                    'linked_accounts' => $links['byPrimary'][$userId]->map(function ($l) {
                        $linkedPayment = SubscriptionPayment::where('subscription_id', $l->linkedAccount?->subscription?->id)
                            ->whereNotNull('covered_by_payment_id')
                            ->latest()
                            ->first();
                        return [
                            'id' => $l->linked_account_id,        // user id — keep for display/lookups elsewhere
                            'payment_id' => $linkedPayment?->id,   // NEW — the actual transaction row id
                            'name' => $l->linkedAccount?->name ?? ('Account #' . $l->linked_account_id),
                            'status' => $l->status,
                            'amount' => $linkedPayment?->amount,
                        ];
                    })->values(),
                ];
            }

            if ($links['byLinked']->has($userId)) {
                $link = $links['byLinked'][$userId];
                return [
                    'is_primary' => false,
                    'primary_name' => $link->primaryAccount?->name,
                    'primary_id' => $link->primary_account_id,
                    'status' => $link->status,
                ];
            }

            return null; // not part of any AccountLink
        };

        foreach ($rows as $row) {
            if ($row->source === 'individual') {
                $payment = $individualPayments->get($row->id);
                $row->household_name = $payment?->subscription?->user?->name
                    ?? $payment?->payer_name;
                $row->linked_accounts = [];
                $row->account_link = $buildAccountLink($payment?->subscription?->user_id);
                $row->proof_of_payment_url = $payment?->proof_of_payment
                    ? Storage::url($payment->proof_of_payment)
                    : null;
                continue;
            }

            $payment = $estatePayments->get($row->id);
            $channelSubscription = $payment?->channelSubscription;

            $row->household_name = $channelSubscription?->channel?->name;
            $row->linked_accounts = $channelSubscription?->subscriptions
                ?->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->user?->name ?? ('Household #' . $s->id),
                    'account_link' => $buildAccountLink($s->user_id),
                ])
                ->values() ?? [];
            $row->account_link = null; // estate row itself isn't a User, has no AccountLink
            $row->proof_of_payment_url = $payment?->proof_of_payment
                ? Storage::url($payment->proof_of_payment)
                : null;
        }

        return $rows;
    }

    public function overview(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);

        $rows = DB::query()
            ->fromSub($this->unifiedQuery($from, $to), 'p')
            ->get();

        $paidStatuses = ['paid', 'complete'];

        $revenueThisRange = $rows
            ->whereIn('status', $paidStatuses)
            ->whereNull('covered_by_payment_id')
            ->sum('amount');

        // MRR: paid rows in the last 30 days from "to", regardless of the
        // selected range, since MRR is a point-in-time metric not a range sum.
        $mrrFrom = $to->copy()->subDays(30)->startOfDay();
        $mrrRows = DB::query()
            ->fromSub($this->unifiedQuery($mrrFrom, $to), 'p')
            ->whereIn('status', $paidStatuses)
            ->whereNull('covered_by_payment_id')
            ->sum('amount');

        $activeSubscriptions = DB::table('subscriptions')
            ->where('status', 'active')
            ->count();

        $pastDue = DB::table('subscriptions')
            ->where('status', 'past_due')
            ->count();

        // Monthly revenue series across the selected range, for the trend chart.
        $monthly = DB::query()
            ->fromSub($this->unifiedQuery($from, $to), 'p')
            ->whereIn('status', $paidStatuses)
            ->whereNull('covered_by_payment_id')
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'mrr' => $mrrRows,
            'revenue_in_range' => $revenueThisRange,
            'active_subscriptions' => $activeSubscriptions,
            'past_due' => $pastDue,
            'monthly_series' => $monthly,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function transactions(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);

        $query = DB::query()->fromSub($this->unifiedQuery($from, $to), 'p');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        if ($request->filled('source')) {
            $query->where('source', $request->input('source'));
        }

        $perPage = (int) $request->input('per_page', 25);

        $paginated = $query->orderByDesc('created_at')->paginate($perPage);

        // Attach household_name / linked_accounts / proof_of_payment_url —
        // this is what the frontend's "Household" and "Proof" columns read.
        $paginated->setCollection(
            collect($this->hydrateHouseholdInfo($paginated->getCollection()))
        );

        return response()->json($paginated);
    }

    public function payfastVsEft(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);
        $paidStatuses = ['paid', 'complete'];

        $split = DB::query()
            ->fromSub($this->unifiedQuery($from, $to), 'p')
            ->whereIn('status', $paidStatuses)
            ->selectRaw('payment_method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('payment_method')
            ->get();

        // EFT proofs awaiting review — estate-bulk flow only, per markEftPaid/approveEftPayment.
        // NOTE: kept 'pending_review' as the status filter since that's what
        // this controller used before — ChannelSubscriptionPayment's own
        // isPending() checks 'pending' instead, so confirm which status your
        // markEftPaid()/review flow actually sets and adjust if needed.
        $pendingEft = ChannelSubscriptionPayment::query()
            ->with('channelSubscription.channel')
            ->where('payment_method', 'eft')
            ->where('status', 'pending_review')
            ->orderByDesc('created_at')
            ->get(['id', 'channel_subscription_id', 'amount', 'created_at', 'proof_of_payment'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'channel_subscription_id' => $row->channel_subscription_id,
                'household_name' => $row->channelSubscription?->channel?->name,
                'amount' => $row->amount,
                'created_at' => $row->created_at,
                'proof_of_payment_url' => $row->proof_of_payment
                    ? Storage::url($row->proof_of_payment)
                    : null,
            ]);

        return response()->json([
            'split' => $split,
            'pending_eft_review' => $pendingEft,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function projections(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);
        $paidStatuses = ['paid', 'complete'];

        // Trailing 3-month growth rate off actuals, independent of the selected
        // range (projections always look back 3 months from "to").
        $trailingFrom = $to->copy()->subMonths(3)->startOfMonth();

        $monthly = DB::query()
            ->fromSub($this->unifiedQuery($trailingFrom, $to), 'p')
            ->whereIn('status', $paidStatuses)
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $values = $monthly->values()->all();
        $growthRate = 0.0;

        if (count($values) >= 2) {
            $rates = [];
            for ($i = 1; $i < count($values); $i++) {
                if ($values[$i - 1] > 0) {
                    $rates[] = ($values[$i] - $values[$i - 1]) / $values[$i - 1];
                }
            }
            $growthRate = count($rates) ? array_sum($rates) / count($rates) : 0.0;
        }

        $lastActual = end($values) ?: 0;
        $months = [];
        $running = $lastActual;

        for ($i = 1; $i <= 12; $i++) {
            $running = $running * (1 + $growthRate);
            $months[] = [
                'month' => $to->copy()->addMonths($i)->format('Y-m'),
                'projected' => round($running, 2),
            ];
        }

        return response()->json([
            'growth_rate' => round($growthRate, 4),
            'next_month' => $months[0]['projected'] ?? 0,
            'six_month' => $months[5]['projected'] ?? 0,
            'annualized' => round(array_sum(array_column($months, 'projected')), 2),
            'series' => $months,
        ]);
    }

    /**
     * All-time totals, independent of the date-range picker — for a
     * "Lifetime" tab so revenue since inception isn't buried inside a
     * 30-day-default MRR figure.
     *
     * NOTE: add a route for this — e.g.
     * Route::get('/admin/finance/lifetime', [FinanceController::class, 'lifetime']);
     */
    public function lifetime(Request $request)
    {
        $paidStatuses = ['paid', 'complete'];
        $sinceEpoch = Carbon::createFromTimestamp(0);
        $now = now()->endOfDay();

        $rows = DB::query()
            ->fromSub($this->unifiedQuery($sinceEpoch, $now), 'p')
            ->whereIn('status', $paidStatuses)
            ->get();

        $monthly = DB::query()
            ->fromSub($this->unifiedQuery($sinceEpoch, $now), 'p')
            ->whereIn('status', $paidStatuses)
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'total_revenue' => $rows->sum('amount'),
            'individual_revenue' => $rows->where('source', 'individual')->sum('amount'),
            'estate_revenue' => $rows->where('source', 'estate')->sum('amount'),
            'total_paid_transactions' => $rows->count(),
            'first_payment_at' => $rows->min('created_at'),
            'monthly_series' => $monthly,
        ]);
    }


    private function resolveAccountLinks($userIds): array
    {
        $userIds = collect($userIds)->filter()->unique()->values();

        $links = AccountLink::with(['primaryAccount', 'linkedAccount'])
            ->where(function ($q) use ($userIds) {
                $q->whereIn('primary_account_id', $userIds)
                ->orWhereIn('linked_account_id', $userIds);
            })
            ->get();

        return [
            'byPrimary' => $links->groupBy('primary_account_id'), // primary -> [links]
            'byLinked'  => $links->keyBy('linked_account_id'),    // linked -> its one link
        ];
    }
}