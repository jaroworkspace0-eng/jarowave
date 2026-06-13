@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fef2f2; color:#dc2626; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fecaca;">
        Payment Rejected
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your EFT proof could not be verified
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $user->name }}, unfortunately your proof of payment for <strong style="color:#1a1a2e;">{{ $channelSubscription->channel->name }}</strong> was not accepted.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Estate</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $channelSubscription->channel->name }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Payment Reference</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $payment->merchant_reference }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Amount</td>
        <td style="padding:4px 0; font-size:18px; font-weight:800; color:#dc2626; text-align:right;">
            R{{ number_format($payment->amount, 2) }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Reason for Rejection</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#dc2626; text-align:right;">
            {{ $reason }}
        </td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0; line-height:1.6;">
    Please log in to your estate dashboard, review the rejection reason,<br>and resubmit a valid proof of payment.
</p>

@component('mail::button', ['url' => 'https://admin.jaroworkspace.com/estate/dashboard', 'color' => 'primary'])
Resubmit Payment
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:billing@echolink.co.za" style="color:#f97316; text-decoration:none;">billing@echolink.co.za</a>
</p>

@endcomponent