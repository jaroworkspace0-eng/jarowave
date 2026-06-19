@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:{{ $daysOverdue >= 7 ? '#fef2f2' : '#fff7ed' }}; color:{{ $daysOverdue >= 7 ? '#ef4444' : '#f97316' }}; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid {{ $daysOverdue >= 7 ? '#fca5a5' : '#fdba74' }};">
        {{ $daysOverdue >= 7 ? 'Final Notice' : 'Payment Overdue' }}
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    {{ $channel->name }}'s bulk billing payment is overdue
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $billingContact->name }},
    @if($daysOverdue >= 7)
        if payment is not received today, all opted-in households on this estate will be suspended.
    @else
        please submit your EFT payment to avoid any interruption to your residents' protection.
    @endif
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Estate</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $channel->name }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Days Overdue</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#ef4444; text-align:right;">{{ $daysOverdue }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Households Covered</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $channelSubscription->household_count }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Amount Due</td>
        <td style="padding:4px 0; font-size:18px; font-weight:800; color:#f97316; text-align:right;">
            R{{ number_format($channelSubscription->total_amount, 2) }}
        </td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    @if($daysOverdue >= 7)
        SOS panic alerts, community safety notifications, and guard communication are about to be
        <strong style="color:#ef4444;">disabled</strong> for every opted-in household on this estate.
    @else
        Submit your EFT proof of payment as soon as possible to keep your residents' protection active.
    @endif
</p>

@component('mail::button', ['url' => 'https://account.jaroworkspace.com/dashboard.html', 'color' => 'primary'])
Submit Payment
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Already paid? This may take a moment to reflect once our team reviews your submission.
    Questions? Contact us at
    <a href="mailto:jaroworkspace0@gmail.com" style="color:#f97316; text-decoration:none;">jaroworkspace0@gmail.com</a>
</p>

@endcomponent