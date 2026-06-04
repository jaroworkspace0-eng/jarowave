@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#f0fdf4; color:#16a34a; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #86efac;">
        Payout Processed
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your payout has been sent
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $client->name }}, your Echo Link earnings have been transferred to your bank account.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Payout Reference</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $payout->reference }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">EFT Reference</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $payout->transfer_reference }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Amount Transferred</td>
        <td style="padding:4px 0; font-size:18px; font-weight:800; color:#16a34a; text-align:right;">
            R{{ number_format($payout->net_amount, 2) }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Households Covered</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $earningCount }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Gross Collected</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            R{{ number_format($payout->gross_amount, 2) }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Platform Fee</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#dc2626; text-align:right;">
            -R{{ number_format($payout->platform_fee, 2) }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Paid On</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $payout->paid_at->format('d M Y') }}
        </td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    You can view your full payout history in your Echo Link dashboard.
</p>

@component('mail::button', ['url' => config('app.url') . '/payouts', 'color' => 'primary'])
View Payout Dashboard
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:billing@echolink.co.za" style="color:#f97316; text-decoration:none;">billing@echolink.co.za</a>
</p>

@endcomponent