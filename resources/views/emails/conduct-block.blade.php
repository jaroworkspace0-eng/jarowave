@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fff1f2; color:#be123c; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fda4af;">
        Conduct Block
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your Echo Link SOS has been suspended
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $userName }}, your SOS panic button has been suspended by your neighbourhood administrator due to reported misuse.
</p>

---

{{-- ── REASON BLOCK ── --}}
<div style="background:#fff1f2; border:1px solid #fda4af; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:12px; font-weight:700; color:#be123c; text-transform:uppercase; letter-spacing:1px; margin:0 0 6px;">Reason for suspension</p>
    <p style="font-size:13px; color:#881337; margin:0; font-weight:600;">{{ $reason }}</p>
</div>

{{-- ── WHAT THIS MEANS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:0 0 12px;">What this means</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff1f2; border:1px solid #fda4af; border-radius:50%; font-size:11px; font-weight:800; color:#be123c;">1</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">SOS disabled</strong> - your panic button is currently inactive. Security patrollers will not receive alerts from your device.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">2</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Contact your administrator</strong> - if you believe this is incorrect, reach out to your neighbourhood watch administrator to appeal.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:50%; font-size:11px; font-weight:800; color:#16a34a;">3</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Once lifted</strong> - your SOS will be instantly restored. No action needed on the app.
        </td>
    </tr>
</table>

---

{{-- ── SUSPENSION DETAILS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:24px 0 12px;">Suspension details</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Account</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $userName }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Status</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#be123c; text-align:right;">SOS Suspended</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Suspended on</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ now()->format('d M Y, H:i') }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Subscription</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#f97316; text-align:right;">R80/month — still active</td>
    </tr>
</table>

@component('mail::button', ['url' => 'mailto:support@jaroworkspace.com', 'color' => 'primary'])
Contact Administrator →
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