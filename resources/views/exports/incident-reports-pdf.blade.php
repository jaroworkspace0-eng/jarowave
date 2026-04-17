<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: sans-serif; font-size: 10px; color: #1a1a2e; background: #fff; }

        .header {
            background: #0f172a;
            color: #fff;
            padding: 16px 24px;
            margin-bottom: 16px;
        }
        .header-top { display: flex; justify-content: space-between; align-items: center; }
        .header-title { font-size: 18px; font-weight: 900; letter-spacing: -0.5px; }
        .header-sub { font-size: 10px; color: #94a3b8; margin-top: 2px; }
        .header-meta { text-align: right; font-size: 9px; color: #64748b; }
        .header-meta strong { color: #f1f5f9; }

        .summary {
            display: flex;
            gap: 12px;
            padding: 0 24px;
            margin-bottom: 16px;
        }
        .summary-card {
            flex: 1;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
            text-align: center;
        }
        .summary-card .val { font-size: 20px; font-weight: 900; color: #0f172a; }
        .summary-card .lbl { font-size: 8px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }

        .filters {
            padding: 0 24px;
            margin-bottom: 14px;
            font-size: 9px;
            color: #64748b;
        }
        .filters span { margin-right: 16px; }
        .filters strong { color: #1e293b; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        thead tr {
            background: #0f172a;
            color: #fff;
        }
        thead th {
            padding: 8px 10px;
            text-align: left;
            font-weight: 700;
            font-size: 8px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        tbody tr { border-bottom: 1px solid #f1f5f9; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody td { padding: 7px 10px; vertical-align: top; }

        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 20px;
            font-size: 8px;
            font-weight: 700;
            border: 1px solid;
        }
        .badge-misuse    { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .badge-legit     { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
        .badge-pending   { background: #fffbeb; color: #b45309; border-color: #fcd34d; }
        .badge-reviewed  { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
        .badge-warned    { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
        .badge-blocked   { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .badge-dismissed { background: #f8fafc; color: #64748b; border-color: #e2e8f0; }

        .narrative { max-width: 200px; line-height: 1.4; color: #475569; font-style: italic; }
        .muted { color: #94a3b8; }

        .footer {
            margin-top: 20px;
            padding: 12px 24px;
            border-top: 1px solid #e2e8f0;
            font-size: 8px;
            color: #94a3b8;
            display: flex;
            justify-content: space-between;
        }
        .page-num { text-align: right; }
    </style>
</head>
<body>

    <div class="header">
        <div class="header-top">
            <div>
                <div class="header-title">📋 Echo Link — Incident Reports</div>
                <div class="header-sub">SOS Alert Incident Report Export</div>
            </div>
            <div class="header-meta">
                <div>Period: <strong>{{ $date_from }} to {{ $date_to }}</strong></div>
                <div>Generated: <strong>{{ $generated_at }}</strong></div>
                <div>Total records: <strong>{{ $total }}</strong></div>
            </div>
        </div>
    </div>

    <div class="summary">
        <div class="summary-card">
            <div class="val">{{ $total }}</div>
            <div class="lbl">Total Reports</div>
        </div>
        <div class="summary-card">
            <div class="val">{{ $reports->where('outcome', 'misuse')->count() }}</div>
            <div class="lbl">Misuse</div>
        </div>
        <div class="summary-card">
            <div class="val">{{ $reports->where('outcome', 'legitimate')->count() }}</div>
            <div class="lbl">Legitimate</div>
        </div>
        <div class="summary-card">
            <div class="val">{{ $reports->where('status', 'pending')->count() }}</div>
            <div class="lbl">Pending Review</div>
        </div>
        <div class="summary-card">
            <div class="val">{{ $reports->where('status', 'warned')->count() }}</div>
            <div class="lbl">Warned</div>
        </div>
        <div class="summary-card">
            <div class="val">{{ $reports->where('status', 'blocked')->count() }}</div>
            <div class="lbl">Blocked</div>
        </div>
    </div>

    @if($filters['status'] || $filters['outcome'] || $filters['search'])
    <div class="filters">
        <strong>Filters applied:</strong>
        @if($filters['status'])   <span>Status: <strong>{{ ucfirst($filters['status']) }}</strong></span> @endif
        @if($filters['outcome'])  <span>Outcome: <strong>{{ ucfirst($filters['outcome']) }}</strong></span> @endif
        @if($filters['search'])   <span>Search: <strong>{{ $filters['search'] }}</strong></span> @endif
    </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Household</th>
                <th>Patroller</th>
                <th>Outcome</th>
                <th>Category</th>
                <th>Narrative</th>
                <th>Arrived</th>
                <th>Departed</th>
                <th>Injuries</th>
                <th>Damage</th>
                <th>Status</th>
                <th>Admin Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($reports as $report)
            <tr>
                <td class="muted">{{ $report->id }}</td>
                <td style="white-space:nowrap">{{ $report->created_at->format('d M Y') }}<br><span class="muted">{{ $report->created_at->format('H:i') }}</span></td>
                <td>
                    <strong>{{ $report->household?->name ?? '—' }}</strong><br>
                    <span class="muted">{{ $report->household?->phone ?? '' }}</span>
                </td>
                <td>{{ $report->reporter?->name ?? '—' }}</td>
                <td>
                    @if($report->outcome === 'misuse')
                        <span class="badge badge-misuse">⚠ Misuse</span>
                    @else
                        <span class="badge badge-legit">✓ Legit</span>
                    @endif
                </td>
                <td style="white-space:nowrap">
                    {{ $report->misuse_category ? ucwords(str_replace('_', ' ', $report->misuse_category)) : '—' }}
                </td>
                <td class="narrative">{{ Str::limit($report->narrative, 120) }}</td>
                <td class="muted" style="white-space:nowrap">{{ $report->arrived_at?->format('d M H:i') ?? '—' }}</td>
                <td class="muted" style="white-space:nowrap">{{ $report->departed_at?->format('d M H:i') ?? '—' }}</td>
                <td style="text-align:center">{{ $report->injuries_reported ? '✓' : '—' }}</td>
                <td style="text-align:center">{{ $report->property_damage   ? '✓' : '—' }}</td>
                <td>
                    <span class="badge badge-{{ $report->status }}">{{ ucfirst($report->status) }}</span>
                </td>
                <td class="muted">{{ Str::limit($report->admin_notes ?? '—', 60) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="13" style="text-align:center; padding:24px; color:#94a3b8;">No reports found for the selected period.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <div>Echo Link · JaroWorkspace · Confidential - For authorised use only</div>
        <div class="page-num">Generated {{ $generated_at }}</div>
    </div>

</body>
</html>