@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fff7ed; color:#f97316; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fed7aa;">
        Payment Confirmed
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your invoice is ready
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $invoice->client->user->name }}, thank you for your payment.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Invoice Number</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $invoice->invoice_number }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Amount Paid</td>
        <td style="padding:4px 0; font-size:18px; font-weight:800; color:#f97316; text-align:right;">
            {{ $invoice->total_in_rands }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Plan</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ ucfirst($invoice->payment->subscription->plan ?? 'Watch Group') }}
            · {{ ucfirst($invoice->payment->subscription->billing_cycle ?? 'Monthly') }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Billing Period</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $invoice->payment->billing_period_start?->format('d M Y') }}
            –
            {{ $invoice->payment->billing_period_end?->format('d M Y') }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Payment Method</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ ucfirst($invoice->payment->gateway) }}
        </td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    Your invoice PDF is attached to this email.<br>You can also view and download it from your billing dashboard.
</p>

@component('mail::button', ['url' => config('app.url') . '/billing', 'color' => 'primary'])
View Billing Dashboard
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:billing@echolink.co.za" style="color:#f97316; text-decoration:none;">billing@echolink.co.za</a>
</p>

@endcomponent