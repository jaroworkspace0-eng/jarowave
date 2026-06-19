@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fef2f2; color:#ef4444; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fca5a5;">
        Protection Paused
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    {{ $channel->name }} is no longer protected
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $billingContact->name }},
    bulk billing payment for this estate was not received within the 7-day grace period,
    so Echo Link protection has been paused for all opted-in households.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Estate</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $channel->name }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Billing Status</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#ef4444; text-align:right;">Suspended</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Households Affected</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $channelSubscription->household_count }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Amount to Reactivate</td>
        <td style="padding:4px 0; font-size:18px; font-weight:800; color:#f97316; text-align:right;">
            R{{ number_format($channelSubscription->total_amount, 2) }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Paused On</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ now()->format('d F Y') }}
        </td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    SOS panic alerts, community safety notifications, and guard communication are
    currently <strong style="color:#ef4444;">disabled</strong> for every opted-in household on this estate.
    Submit your EFT payment to restore full protection.
</p>

@component('mail::button', ['url' => 'https://account.jaroworkspace.com/dashboard.html', 'color' => 'primary'])
Restore Estate Protection
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:jaroworkspace0@gmail.com" style="color:#f97316; text-decoration:none;">jaroworkspace0@gmail.com</a>
</p>

@endcomponent