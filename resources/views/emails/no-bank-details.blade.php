@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fff7ed; color:#f97316; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fed7aa;">
        Action Required
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your payout is on hold
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $client->name }}, we were unable to process your payout because no bank details are on file.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Reason</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#dc2626; text-align:right;">
            No bank details on file
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">What to do</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            Add your bank details in your dashboard
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Payout Schedule</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            1st of every month
        </td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    Once your bank details are added, your payout will be processed on the next available date.<br>
    Your earnings are safe and will not be lost.
</p>

@component('mail::button', ['url' => config('app.url') . '/payouts', 'color' => 'primary'])
Add Bank Details
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:billing@echolink.co.za" style="color:#f97316; text-decoration:none;">billing@echolink.co.za</a>
</p>

@endcomponent