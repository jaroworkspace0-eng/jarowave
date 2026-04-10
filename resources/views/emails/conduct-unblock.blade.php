@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#f0fdf4; color:#16a34a; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #bbf7d0;">
        SOS Restored
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your Echo Link SOS is active again
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $userName }}, your neighbourhood administrator has lifted the conduct suspension on your account. Your SOS panic button is now fully restored.
</p>

---

{{-- ── CONFIRMATION BLOCK ── --}}
<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#15803d; margin:0; font-weight:600;">✓ Your SOS panic button is now active and security patrollers will receive your alerts immediately.</p>
</div>

{{-- ── WHAT THIS MEANS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:0 0 12px;">What happens now</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:50%; font-size:11px; font-weight:800; color:#16a34a;">1</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">SOS restored</strong> — your panic button is active immediately. No action needed on the app.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:50%; font-size:11px; font-weight:800; color:#16a34a;">2</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Warning removed</strong> — the suspension banner on your device will disappear the next time you open Echo Link.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">3</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Stay responsible</strong> — please use the SOS button only for genuine emergencies to maintain trust with your neighbourhood security team.
        </td>
    </tr>
</table>

---

{{-- ── ACCOUNT DETAILS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:24px 0 12px;">Account details</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Account</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $userName }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Status</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#16a34a; text-align:right;">✓ SOS Active</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Restored on</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ now()->format('d M Y, H:i') }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Subscription</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#f97316; text-align:right;">R80/month — active</td>
    </tr>
</table>

@component('mail::button', ['url' => config('app.account_url', 'https://account.jaroworkspace.com') . '/dashboard.html', 'color' => 'primary'])
Open Echo Link Dashboard →
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