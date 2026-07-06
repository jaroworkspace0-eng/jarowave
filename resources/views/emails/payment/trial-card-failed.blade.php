@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fff7ed; color:#f97316; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fed7aa;">
        Card Verification Failed
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    We couldn't verify your card
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $userName }}, we tried to verify your card details but the attempt didn't go through.
</p>

---

{{-- ── REASSURANCE ── --}}
<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#15803d; margin:0; font-weight:600;">✓ Your free trial is unaffected. Your SOS access continues normally until {{ $trialEndsAtFormatted }}.</p>
</div>

<p style="font-size:13px; color:#444; margin:0 0 24px;">
    To make sure your subscription can activate smoothly once your trial ends, please update your card details
    before {{ $trialEndsAtFormatted }}.
</p>

@component('mail::button', ['url' => config('app.account_url') . '/dashboard.html', 'color' => 'primary'])
Update Card Details →
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