<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a2e; background: #fff; }

        .header-table { width: 100%; background-color: #0f172a; margin-bottom: 14px; }
        .header-table td { padding: 14px 20px; color: #fff; vertical-align: middle; }
        .header-title { font-size: 16px; font-weight: bold; color: #fff; }
        .header-sub { font-size: 9px; color: #94a3b8; margin-top: 2px; }
        .header-meta { text-align: right; font-size: 8px; color: #94a3b8; line-height: 1.6; }
        .header-meta strong { color: #f1f5f9; }

        .summary-table { width: 100%; margin-bottom: 12px; border-collapse: collapse; }
        .summary-table td { background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px 10px; text-align: center; }
        .summary-val { font-size: 18px; font-weight: bold; color: #0f172a; }
        .summary-lbl { font-size: 7px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }

        .filters { font-size: 8px; color: #64748b; margin-bottom: 10px; padding: 0 2px; }
        .filters strong { color: #1e293b; }

        .main-table { width: 100%; border-collapse: collapse; }
        .main-table thead tr { background-color: #0f172a; }
        .main-table thead th { color: #fff; padding: 7px 8px; text-align: left; font-size: 7.5px; font-weight: bold; letter-spacing: 0.3px; text-transform: uppercase; white-space: nowrap; border: none; }
        .main-table tbody tr { border-bottom: 1px solid #f1f5f9; }
        .main-table tbody tr.even { background-color: #f8fafc; }
        .main-table tbody td { padding: 6px 8px; vertical-align: top; font-size: 8.5px; line-height: 1.4; }

        .badge { display: inline-block; padding: 2px 6px; border-radius: 10px; font-size: 7.5px; font-weight: bold; border: 1px solid; }
        .badge-misuse    { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .badge-legit     { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
        .badge-pending   { background: #fffbeb; color: #b45309; border-color: #fcd34d; }
        .badge-reviewed  { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
        .badge-warned    { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
        .badge-blocked   { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .badge-dismissed { background: #f8fafc; color: #64748b; border-color: #e2e8f0; }

        .name { font-weight: bold; color: #0f172a; }
        .muted { color: #94a3b8; font-size: 7.5px; }
        .narrative { color: #475569; font-style: italic; }
        .center { text-align: center; }

        .footer-table { width: 100%; margin-top: 16px; border-top: 1px solid #e2e8f0; }
        .footer-table td { padding: 8px 2px; font-size: 7.5px; color: #94a3b8; }
        .footer-right { text-align: right; }
        .empty-row td { text-align: center; padding: 24px; color: #94a3b8; font-style: italic; }
    </style>
</head>
<body>

    <table class="header-table" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <div class="header-title">Echo Link — Incident Reports</div>
                <div class="header-sub">SOS Alert Incident Report Export</div>
            </td>
            <td class="header-meta">
                Period: <strong>{{ $date_from }} to {{ $date_to }}</strong><br>
                Generated: <strong>{{ $generated_at }}</strong><br>
                Total records: <strong>{{ $total }}</strong>
            </td>
        </tr>
    </table>

    <table class="summary-table" cellpadding="0" cellspacing="0">
        <tr>
            <td><div class="summary-val">{{ $total }}</div><div class="summary-lbl">Total</div></td>
            <td><div class="summary-val" style="color:#dc2626;">{{ $reports->where('outcome','misuse')->count() }}</div><div class="summary-lbl">Misuse</div></td>
            <td><div class="summary-val" style="color:#16a34a;">{{ $reports->where('outcome','legitimate')->count() }}</div><div class="summary-lbl">Legitimate</div></td>
            <td><div class="summary-val" style="color:#b45309;">{{ $reports->where('status','pending')->count() }}</div><div class="summary-lbl">Pending</div></td>
            <td><div class="summary-val" style="color:#c2410c;">{{ $reports->where('status','warned')->count() }}</div><div class="summary-lbl">Warned</div></td>
            <td><div class="summary-val" style="color:#dc2626;">{{ $reports->where('status','blocked')->count() }}</div><div class="summary-lbl">Blocked</div></td>
            <td><div class="summary-val" style="color:#64748b;">{{ $reports->where('status','dismissed')->count() }}</div><div class="summary-lbl">Dismissed</div></td>
        </tr>
    </table>

    @if($filters['status'] || $filters['outcome'] || $filters['search'])
    <div class="filters">
        <strong>Filters:</strong>
        @if($filters['status'])  &nbsp;Status: <strong>{{ ucfirst($filters['status']) }}</strong> @endif
        @if($filters['outcome']) &nbsp;Outcome: <strong>{{ ucfirst($filters['outcome']) }}</strong> @endif
        @if($filters['search'])  &nbsp;Search: <strong>{{ $filters['search'] }}</strong> @endif
    </div>
    @endif

    <table class="main-table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th style="width:25px">#</th>
                <th style="width:52px">Date</th>
                <th style="width:85px">Household</th>
                <th style="width:75px">Patroller</th>
                <th style="width:45px">Outcome</th>
                <th style="width:62px">Category</th>
                <th>Narrative</th>
                <th style="width:52px">Arrived</th>
                <th style="width:52px">Departed</th>
                <th style="width:22px" class="center">Inj.</th>
                <th style="width:22px" class="center">Dmg.</th>
                <th style="width:52px">Status</th>
                <th style="width:80px">Admin Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($reports as $i => $report)
            <tr class="{{ $i % 2 === 0 ? '' : 'even' }}">
                <td class="muted">{{ $report->id }}</td>
                <td>{{ $report->created_at->format('d M Y') }}<br><span class="muted">{{ $report->created_at->format('H:i') }}</span></td>
                <td><span class="name">{{ Str::limit($report->household?->name ?? '—', 20) }}</span><br><span class="muted">{{ $report->household?->phone ?? '' }}</span></td>
                <td>{{ Str::limit($report->reporter?->name ?? '—', 20) }}</td>
                <td>
                    @if($report->outcome === 'misuse')
                        <span class="badge badge-misuse">Misuse</span>
                    @else
                        <span class="badge badge-legit">Legit</span>
                    @endif
                </td>
                <td class="muted">{{ $report->misuse_category ? ucwords(str_replace('_',' ',$report->misuse_category)) : '—' }}</td>
                <td class="narrative">{{ Str::limit($report->narrative, 100) }}</td>
                <td class="muted">{{ $report->arrived_at?->format('d M H:i') ?? '—' }}</td>
                <td class="muted">{{ $report->departed_at?->format('d M H:i') ?? '—' }}</td>
                <td class="center" style="{{ $report->injuries_reported ? 'color:#dc2626;font-weight:bold;' : 'color:#94a3b8;' }}">{{ $report->injuries_reported ? 'Yes' : 'No' }}</td>
                <td class="center" style="{{ $report->property_damage ? 'color:#dc2626;font-weight:bold;' : 'color:#94a3b8;' }}">{{ $report->property_damage ? 'Yes' : 'No' }}</td>
                <td><span class="badge badge-{{ $report->status }}">{{ ucfirst($report->status) }}</span></td>
                <td class="muted">{{ Str::limit($report->admin_notes ?? '—', 50) }}</td>
            </tr>
            @empty
            <tr class="empty-row"><td colspan="13">No reports found for the selected period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="footer-table" cellpadding="0" cellspacing="0">
        <tr>
            <td>Echo Link &middot; JaroWorkspace &middot; Confidential — For authorised use only</td>
            <td class="footer-right">Generated {{ $generated_at }}</td>
        </tr>
    </table>

</body>
</html>