@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#f0fdf4; color:#16a34a; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #bbf7d0;">
        Payment Successful
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your Echo Link subscription is active
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $userName }}, your payment was processed successfully. Your SOS and emergency features are fully active.
</p>

---

{{-- ── PAYMENT DETAILS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:0 0 12px;">Payment details</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    @if($amount)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Amount paid</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">R{{ number_format($amount, 2) }}</td>
    </tr>
    @endif
    @if($periodEnd)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Next billing date</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $periodEnd }}</td>
    </tr>
    @endif
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">SOS status</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#16a34a; text-align:right;">Active ✓</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Subscription</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#f97316; text-align:right;">R80/month</td>
    </tr>
</table>

<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#166534; margin:0; font-weight:600;">✓ Your SOS button is active and your household is protected.</p>
</div>

---

@component('mail::button', ['url' => config('app.url') . '/dashboard', 'color' => 'primary'])
Go to Dashboard →
@endcomponent

<p style="text-align:center; font-size:13px; color:#888; margin:16px 0 0;">
    Questions? Email us at
    <a href="mailto:support@jaroworkspace.com" style="color:#f97316; text-decoration:none; font-weight:600;">support@jaroworkspace.com</a>
</p>

---

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    © {{ date('Y') }} Echo Link · JaroWorkspace ·
    <a href="https://policy.jaroworkspace.com" style="color:#f97316; text-decoration:none;">Privacy Policy</a>
</p>

@endcomponent
