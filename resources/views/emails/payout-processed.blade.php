<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .wrap { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        .header { background: linear-gradient(135deg, #0f172a, #1e293b); padding: 32px; text-align: center; }
        .header h1 { color: #fff; font-size: 22px; margin: 0 0 4px; }
        .header p  { color: #64748b; font-size: 13px; margin: 0; }
        .amount-block { background: #f97316; padding: 24px; text-align: center; }
        .amount-block .amt { font-size: 42px; font-weight: 800; color: #fff; letter-spacing: -2px; }
        .amount-block .lbl { font-size: 13px; color: rgba(255,255,255,.7); margin-top: 4px; }
        .body { padding: 32px; }
        .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .row:last-child { border-bottom: none; }
        .row .lbl { color: #888; }
        .row .val { font-weight: 600; color: #111; }
        .footer { background: #f9f9f9; padding: 20px 32px; font-size: 12px; color: #aaa; text-align: center; }
        .btn { display: inline-block; margin: 24px 0 0; padding: 12px 28px; background: #f97316; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <h1>Echo Link</h1>
        <p>Payout Confirmation</p>
    </div>
    <div class="amount-block">
        <div class="amt">R{{ number_format($payout->net_amount, 2) }}</div>
        <div class="lbl">Transferred to your bank account</div>
    </div>
    <div class="body">
        <p style="color:#333;font-size:15px;margin:0 0 24px;">Hi {{ $client->name }},<br><br>
        Your Echo Link payout has been processed and transferred to your registered bank account.</p>

        <div class="row"><span class="lbl">Reference</span><span class="val">{{ $payout->reference }}</span></div>
        <div class="row"><span class="lbl">EFT Reference</span><span class="val">{{ $payout->transfer_reference }}</span></div>
        <div class="row"><span class="lbl">Households covered</span><span class="val">{{ $earningCount }}</span></div>
        <div class="row"><span class="lbl">Gross collected</span><span class="val">R{{ number_format($payout->gross_amount, 2) }}</span></div>
        <div class="row"><span class="lbl">Platform fee ({{ 100 - round($payout->net_amount / $payout->gross_amount * 100) }}%)</span><span class="val" style="color:#dc2626;">-R{{ number_format($payout->platform_fee, 2) }}</span></div>
        <div class="row"><span class="lbl">Your payout</span><span class="val" style="color:#16a34a;">R{{ number_format($payout->net_amount, 2) }}</span></div>
        <div class="row"><span class="lbl">Paid on</span><span class="val">{{ $payout->paid_at->format('d M Y') }}</span></div>

        <p style="font-size:13px;color:#888;margin:24px 0 0;line-height:1.6;">
            If you have any questions about this payout, please contact Echo Link support.
        </p>
    </div>
    <div class="footer">Echo Link · Neighbourhood Safety Platform<br>This is an automated message, please do not reply.</div>
</div>
</body>
</html>