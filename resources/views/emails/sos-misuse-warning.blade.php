@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fffbeb; color:#b45309; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fcd34d;">
        SOS Misuse Warning
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    A complaint has been logged against your SOS use
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $userName }}, your neighbourhood security team has submitted a report regarding a recent SOS alert from your device.
</p>

---

{{-- ── WARNING BLOCK ── --}}
<div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#b45309; margin:0; font-weight:600;">
        This is a formal warning. Continued misuse of the SOS panic button may result in your access being suspended.
    </p>
</div>

{{-- ── REPORT SUMMARY ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:0 0 12px;">What was reported</p>

<div style="background:#f8f9fa; border:1px solid #e9ecef; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#444; margin:0; line-height:1.6; font-style:italic;">
        "{{ $narrative }}"
    </p>
</div>

{{-- ── WHAT THIS MEANS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:0 0 12px;">What happens next</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fffbeb; border:1px solid #fcd34d; border-radius:50%; font-size:11px; font-weight:800; color:#b45309;">1</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">This is warning #{{ $reportCount }}</strong> - our administrators have been notified and are reviewing the report.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fef2f2; border:1px solid #fecaca; border-radius:50%; font-size:11px; font-weight:800; color:#dc2626;">2</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Further misuse</strong> may result in your SOS panic button being suspended without further warning.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:50%; font-size:11px; font-weight:800; color:#16a34a;">3</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">If you believe this report is incorrect</strong> — please contact your neighbourhood administrator to appeal.
        </td>
    </tr>
</table>

---

{{-- ── REMINDER ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:24px 0 12px;">A reminder on correct SOS use</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Account</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $userName }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Reports filed</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#b45309; text-align:right;">{{ $reportCount }} misuse report{{ $reportCount !== 1 ? 's' : '' }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Status</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#b45309; text-align:right;">Warning Issued</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Subscription</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#f97316; text-align:right;">R80/month - still active</td>
    </tr>
</table>

<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#15803d; margin:0; font-weight:600;">
        ✓ The SOS button should only be used in genuine emergencies. Security patrollers put their safety at risk responding to every alert.
    </p>
</div>

@component('mail::button', ['url' => 'https://account.jaroworkspace.com/dashboard.html', 'color' => 'primary'])
View Your Account →
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