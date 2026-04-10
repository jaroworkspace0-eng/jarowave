
@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fef2f2; color:#dc2626; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fecaca;">
        Payment Failed
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your Echo Link payment could not be processed
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $userName }}, your subscription payment{{ $amount ? ' of R' . number_format($amount, 2) : '' }} failed.
    @if($reason) Reason: {{ $reason }}. @endif
</p>

---

{{-- ── GRACE PERIOD WARNING ── --}}
<div style="background:#fef3e2; border:1px solid #fed7aa; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#b45309; margin:0; font-weight:600;">⚠ You have a 24-hour grace period before your SOS is suspended.</p>
</div>

{{-- ── WHAT THIS MEANS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:0 0 12px;">What happens next</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">1</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Now - 24 hours</strong> - your SOS button remains active. Use this time to update your payment details.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fef2f2; border:1px solid #fecaca; border-radius:50%; font-size:11px; font-weight:800; color:#dc2626;">2</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">After 24 hours</strong> - your SOS emergency alert will be suspended until payment is resolved.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:50%; font-size:11px; font-weight:800; color:#16a34a;">3</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Once resolved</strong> - your SOS is instantly re-enabled. No action needed on the app.
        </td>
    </tr>
</table>

---

{{-- ── PAYMENT DETAILS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:24px 0 12px;">Payment details</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    @if($amount)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Amount</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">R{{ number_format($amount, 2) }}</td>
    </tr>
    @endif
    @if($reason)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Reason</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#dc2626; text-align:right;">{{ $reason }}</td>
    </tr>
    @endif
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Grace period ends</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ now()->addHours(24)->format('d M Y, H:i') }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Subscription</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#f97316; text-align:right;">R80/month</td>
    </tr>
</table>

@component('mail::button', ['url' => config('app.account_url') . '/dashboard.html', 'color' => 'primary'])
Update Payment Details →
@endcomponent

<p style="text-align:center; font-size:13px; color:#888; margin:16px 0 0;">
    Need help? Email us at
    <a href="mailto:support@jaroworkspace.com" style="color:#f97316; text-decoration:none; font-weight:600;">support@jaroworkspace.com</a>
</p>

---

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    © {{ date('Y') }} Echo Link · JaroWorkspace ·
    <a href="https://policy.jaroworkspace.com" style="color:#f97316; text-decoration:none;">Privacy Policy</a>
</p>

@endcomponent