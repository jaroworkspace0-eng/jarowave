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
        .warning { background: #fff7ed; border-left: 4px solid #f97316; padding: 16px 24px; margin: 24px; border-radius: 0 8px 8px 0; }
        .warning p { margin: 0; font-size: 14px; color: #92400e; line-height: 1.6; }
        .body { padding: 0 32px 32px; }
        .btn { display: inline-block; margin: 16px 0 0; padding: 12px 28px; background: #f97316; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; }
        .footer { background: #f9f9f9; padding: 20px 32px; font-size: 12px; color: #aaa; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <h1>Echo Link</h1>
        <p>Action Required</p>
    </div>
    <div class="warning">
        <p>⚠ <strong>Your payout is on hold.</strong> We were unable to process your payout because no bank details are on file for your account.</p>
    </div>
    <div class="body">
        <p style="color:#333;font-size:15px;margin:0 0 16px;">Hi {{ $client->name }},</p>
        <p style="color:#555;font-size:14px;line-height:1.7;margin:0 0 16px;">
            Echo Link processes payouts on the 1st of every month. Your earnings are ready to be disbursed, 
            but we need your bank account details before we can transfer your funds.
        </p>
        <p style="color:#555;font-size:14px;line-height:1.7;margin:0 0 24px;">
            Please log in to your Echo Link dashboard and add your banking details under the <strong>Payouts</strong> section.
        </p>
        <a href="{{ config('app.url') }}/payouts" class="btn">Add Bank Details</a>
        <p style="font-size:13px;color:#888;margin:24px 0 0;line-height:1.6;">
            Once your bank details are added, your payout will be processed on the next available date.
        </p>
    </div>
    <div class="footer">Echo Link · Neighbourhood Safety Platform<br>This is an automated message, please do not reply.</div>
</div>
</body>
</html>