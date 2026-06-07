@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    @if($failedPayment)
    <span style="display:inline-block; background:#fef2f2; color:#ef4444; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fca5a5;">
        Payment Failed
    </span>
    @else
    <span style="display:inline-block; background:#fff7ed; color:#f97316; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fed7aa;">
        Payment Reminder
    </span>
    @endif
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    @if($failedPayment)
        Action required: Update your payment details
    @else
        Your next payment is due in {{ $daysLeft }} {{ $daysLeft === 1 ? 'day' : 'days' }}
    @endif
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    @if($failedPayment)
        Hi {{ $user->name }}, we could not process your last payment. Please update your details to avoid suspension.
    @else
        Hi {{ $user->name }}, your Echo Link subscription will be debited automatically on the due date.
    @endif
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Account</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $user->email }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Amount</td>
        <td style="padding:4px 0; font-size:18px; font-weight:800; color:#f97316; text-align:right;">
            R{{ number_format($subscription->price, 2) }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Billing Cycle</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ ucfirst($subscription->billing_cycle) }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Due Date</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:{{ $failedPayment ? '#ef4444' : '#1a1a2e' }}; text-align:right;">
            {{ \Carbon\Carbon::parse($subscription->current_period_end)->format('d F Y') }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Payment Method</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ ucfirst($subscription->gateway ?? 'PayFast') }}
        </td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    @if($failedPayment)
        Your account will be suspended if payment is not resolved within 3 days.
    @else
        No action needed if your payment details are up to date. You can review them in your dashboard.
    @endif
</p>

@component('mail::button', ['url' => 'https://account.jaroworkspace.com/dashboard.html', 'color' => 'primary'])
{{ $failedPayment ? 'Update Payment Details' : 'View Billing Dashboard' }}
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:billing@echolink.co.za" style="color:#f97316; text-decoration:none;">billing@echolink.co.za</a>
</p>

@endcomponent