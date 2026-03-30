<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif !important; }

        body {
            font-family: DejaVu Sans, sans-serif !important;
            font-size: 13px;
            color: #111;
            background: #fff;
        }

        .top-bar {
            height: 5px;
            background: #f97316;
            width: 100%;
        }

        .page {
            padding: 44px 52px;
        }

        /* ── HEADER ── */
        .header-inner {
            display: table;
            width: 100%;
            margin-bottom: 32px;
        }

        .logo-cell {
            display: table-cell;
            vertical-align: middle;
        }

        .invoice-cell {
            display: table-cell;
            vertical-align: top;
            text-align: right;
        }

        .logo-row {
            display: table;
        }

        .logo-img-cell {
            display: table-cell;
            vertical-align: middle;
            padding-right: 12px;
        }

        .logo-img {
            width: 42px;
            height: 42px;
            border-radius: 10px;
        }

        .logo-text-cell {
            display: table-cell;
            vertical-align: middle;
        }

        .logo-name {
            font-size: 22px;
            font-weight: 700;
            color: #111;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }

        .logo-name span { color: #f97316; }

        .logo-tagline {
            font-size: 9px;
            color: #aaa;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-top: 3px;
        }

        .inv-word {
            font-size: 9px;
            font-weight: 700;
            color: #aaa;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .inv-number {
            font-size: 16px;
            font-weight: 700;
            color: #111;
            margin-bottom: 10px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }

        .status-pill {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .status-paid   { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .status-issued { background: #fff7ed; color: #f97316; border: 1px solid #fed7aa; }
        .status-void   { background: #f5f5f5; color: #888;    border: 1px solid #e5e5e5; }
        .status-draft  { background: #f5f5f5; color: #aaa;    border: 1px solid #e5e5e5; }

        .divider { height: 1px; background: #f0f0f0; margin-bottom: 28px; }

        /* ── PARTIES ── */
        .parties {
            display: table;
            width: 100%;
            margin-bottom: 28px;
        }

        .party-from { display: table-cell; width: 50%; vertical-align: top; }
        .party-to   { display: table-cell; width: 50%; vertical-align: top; text-align: right; }

        .party-lbl {
            font-size: 9px;
            font-weight: 700;
            color: #f97316;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .party-name {
            font-size: 14px;
            font-weight: 700;
            color: #111;
            margin-bottom: 5px;
        }

        .party-detail {
            font-size: 11px;
            color: #777;
            line-height: 1.8;
        }

        /* ── META STRIP ── */
        .meta-strip {
            background: #fafafa;
            border: 1px solid #f0f0f0;
            border-radius: 10px;
            margin-bottom: 28px;
            display: table;
            width: 100%;
        }

        .meta-item {
            display: table-cell;
            padding: 14px 16px;
            border-right: 1px solid #efefef;
            vertical-align: top;
        }

        .meta-item div {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }

        .meta-item:last-child { border-right: none; }

        .meta-lbl {
            font-size: 8px;
            font-weight: 700;
            color: #999999;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 5px;
        }

        .meta-val {
            font-size: 11px;
            font-weight: 600;
            color: #111;
            line-height: 1.4;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }

        .meta-val-mono {
            font-size: 10px;
            font-weight: 600;
            color: #999999;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }

        /* ── TABLE ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        thead tr { background: #111; }

        th {
            text-align: left;
            font-size: 8px;
            font-weight: 700;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 11px 14px;
        }

        th.right { text-align: right; }
        tbody tr { border-bottom: 1px solid #f5f5f5; }

        td {
            padding: 15px 14px;
            font-size: 12px;
            color: #333;
            vertical-align: top;
        }

        td.right { text-align: right; font-weight: 700; color: #111; }

        .td-title { font-weight: 700; color: #111; font-size: 13px; margin-bottom: 3px; }
        .td-sub   { font-size: 10px; color: #999999; }

        /* ── TOTALS ── */
        .totals-outer  { text-align: right; margin-bottom: 36px; }
        .totals-inner  { display: inline-block; width: 260px; text-align: left; }

        .total-row {
            display: table;
            width: 100%;
            padding: 7px 0;
            font-size: 12px;
            color: #777;
            border-bottom: 1px solid #f5f5f5;
        }

        .total-row-label { display: table-cell; font-family: 'DejaVu Sans, sans-serif' }
        .total-row-value { display: table-cell; text-align: right; font-family: 'DejaVu Sans, sans-serif';}

        .total-row.discount .total-row-label,
        .total-row.discount .total-row-value { color: #16a34a; font-weight: 600; font-family: 'DejaVu Sans, sans-serif';}

        .total-row.vat .total-row-label,
        .total-row.vat .total-row-value { color: #999999; font-size: 10px; font-family: 'DejaVu Sans, sans-serif'; }

        .grand-block {
            background: #111;
            border-radius: 10px;
            padding: 14px 16px;
            margin-top: 8px;
            display: table;
            width: 100%;
        }

        .grand-left  { display: table-cell; vertical-align: middle; }
        .grand-right { display: table-cell; vertical-align: middle; text-align: right; }

        .grand-lbl {
            font-size: 9px;
            color: rgba(255,255,255,0.5);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
        }

        .grand-amt {
            font-size: 22px;
            font-weight: 700;
            color: #f97316;
            letter-spacing: -1px;
        }

        /* ── FOOTER ── */
        .footer {
            border-top: 1px solid #f0f0f0;
            padding-top: 18px;
            display: table;
            width: 100%;
        }

        .footer-left  { display: table-cell; vertical-align: bottom; }
        .footer-right { display: table-cell; vertical-align: bottom; text-align: right; }

        .footer-note { font-size: 10px; color: #999999; line-height: 1.8; }
        .footer-note strong { color: #999999; }

        .footer-brand { font-size: 14px; font-weight: 700; color: #999999; }
        .footer-brand span { color: #f97316; }
        .footer-url { font-size: 10px; color: #999999; margin-top: 2px; }

        .bottom-bar { height: 4px; background: #f97316; width: 100%; margin-top: 28px; }
    </style>
</head>
<body>

<div class="top-bar"></div>

<div class="page">

    {{-- HEADER --}}
    <div class="header-inner">
        <div class="logo-cell">
            <div class="logo-row">
                <div class="logo-img-cell">
                    <img class="logo-img" src="{{ public_path('images/echolink.png') }}" alt="Echo Link" />
                </div>
                <div class="logo-text-cell">
                    <div class="logo-name">Echo <span>Link</span></div>
                    <div class="logo-tagline">Community Safety Platform</div>
                </div>
            </div>
        </div>
        <div class="invoice-cell">
            <div class="inv-word">Invoice</div>
            <div class="inv-number">{{ $invoice->invoice_number }}</div>
            <span class="status-pill status-{{ $invoice->status }}">
                {{ strtoupper($invoice->status) }}
            </span>
        </div>
    </div>

    <div class="divider"></div>

    {{-- PARTIES --}}
    <div class="parties">
        <div class="party-from">
            <div class="party-lbl">From</div>
            <div class="party-name">Echo Link (Pty) Ltd</div>
            <div class="party-detail">
                billing@jaroworkspace.com<br>
                South Africa<br>
                jaroworkspace.com
            </div>
        </div>
        <div class="party-to">
            <div class="party-lbl">Bill To</div>
            <div class="party-name">{{ $invoice->client->user->name }}</div>
            <div class="party-detail">
                {{ $invoice->client->user->email }}<br>
                @if($invoice->client->user->phone)
                    {{ $invoice->client->user->phone }}<br>
                @endif
                South Africa
            </div>
        </div>
    </div>

    {{-- META STRIP --}}
    <div class="meta-strip">
        <div class="meta-item">
            <div class="meta-lbl">Issue Date</div>
            <div class="meta- val" style="font-family: DejaVu Sans, sans-serif;">{{ $invoice->issued_at?->format('d M Y') ?? '—' }}</div>
        </div>
        <div class="meta-item">
            <div class="meta-lbl">Billing Period</div>
            <div class="meta -val" style="font-family: DejaVu Sans, sans-serif; font-size: 12px;">
                {{ $invoice->payment->billing_period_start?->format('d M Y') }}
                –
                {{ $invoice->payment->billing_period_end?->format('d M Y') }}
            </div>
        </div>
        <div class="meta-item">
            <div class="meta-lbl">Gateway</div>
            <div class="meta- val" style="font-family: DejaVu Sans, sans-serif; font-size: 12px;">{{ ucfirst($invoice->payment->gateway) }}</div>
        </div>
        <div class="meta-item">
            <div class="meta-lbl">Transaction ID</div>
            <div class="meta-val- mono">{{ $invoice->payment->gateway_transaction_id ?? '—' }}</div>
        </div>
        <div class="meta-item">
            <div class="meta-lbl">Currency</div>
            <div class="meta- val" style="font-family: DejaVu Sans, sans-serif; font-size: 12px;">{{ $invoice->currency }}</div>
        </div>
    </div>

    {{-- LINE ITEMS --}}
    <table>
        <thead>
            <tr>
                <th style="width:45%">Description</th>
                <th>Plan</th>
                <th>Billing Cycle</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div class="td-title">Echo Link Subscription</div>
                    <div class="td-sub">
                        {{ $invoice->payment->billing_period_start?->format('d M Y') }}
                        to
                        {{ $invoice->payment->billing_period_end?->format('d M Y') }}
                    </div>
                </td>
                <td>{{ ucfirst($invoice->payment->subscription->plan ?? 'Watch Group') }}</td>
                <td>{{ ucfirst($invoice->payment->subscription->billing_cycle ?? 'Monthly') }}</td>
                <td class="right">{{ $invoice->subtotal_in_rands }}</td>
            </tr>
        </tbody>
    </table>

    {{-- TOTALS --}}
    <div class="totals-outer">
        <div class="totals-inner">

            <div class="total-row">
                <div class="total-row-label">Subtotal</div>
                <div class="total-row-value">{{ $invoice->subtotal_in_rands }}</div>
            </div>

            @if($invoice->discount_amount > 0)
            <div class="total-row discount">
                <div class="total-row- label" style="color:green;font-family: DejaVu Sans, sans-serif !important;">
                    Annual discount ({{ $invoice->payment->subscription->discount_percentage }}% off)
                </div>
                <div class="total-row-valu" style="color:green;display: table-cell; text-align: right; font-family: 'DejaVu Sans, sans-serif';">− {{ $invoice->discount_in_rands }}</div>
            </div>
            @endif

            <div class="total-row vat">
                <div class="total-row-label">VAT (0% - exempt)</div>
                <div class="total-row-value">R0.00</div>
            </div>

            <div class="grand-block">
                <div class="grand-left">
                    <div class="grand-lbl">Total Due</div>
                </div>
                <div class="grand-right">
                    <div class="grand-amt">{{ $invoice->total_in_rands }}</div>
                </div>
            </div>

        </div>
    </div>

    {{-- FOOTER --}}
    <div class="footer">
        <div class="footer-left">
            <div class="footer-note">
                Thank you for keeping your community safe.<br>
                Questions? <strong>billing@jaroworkspace.com</strong><br>
                This is a computer-generated invoice · No signature required
            </div>
        </div>
        <div class="footer-right">
            <div class="footer-brand">Echo <span>Link</span></div>
            <div class="footer-url">policy.jaroworkspace.com</div>
        </div>
    </div>

    <div class="bottom-bar"></div>

</div>

</body>
</html>