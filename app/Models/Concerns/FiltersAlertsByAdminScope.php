<?php

namespace App\Models\Concerns;

use App\Models\AdminAlertScope;
use Illuminate\Database\Eloquent\Builder;

// Add `use FiltersAlertsByAdminScope;` to your Alert model, then call
// Alert::visibleTo($admin)->... wherever alerts are queried for a
// dashboard/admin session (including the endpoint the frontend polls
// or the initial Inertia page load).
//
// Rule: an admin with NO scope rows sees everything (unrestricted,
// same as today). An admin with at least one scope row is restricted
// to the union of their channel scopes and household scopes.
//
// Assumes the alerts table has `channel_id` and `household_id` columns —
// adjust the column names below if yours differ.
trait FiltersAlertsByAdminScope
{
    public function scopeVisibleTo(Builder $query, $admin): Builder
    {
        $scopes = AdminAlertScope::where('admin_id', $admin->id)->get();

        if ($scopes->isEmpty()) {
            return $query;
        }

        $channelIds = $scopes->where('scope_type', 'channel')->pluck('scope_id');
        $householdIds = $scopes->where('scope_type', 'household')->pluck('scope_id');

        return $query->where(function (Builder $q) use ($channelIds, $householdIds) {
            if ($channelIds->isNotEmpty()) {
                $q->orWhereIn('channel_id', $channelIds);
            }
            if ($householdIds->isNotEmpty()) {
                $q->orWhereIn('household_id', $householdIds);
            }
        });
    }
}