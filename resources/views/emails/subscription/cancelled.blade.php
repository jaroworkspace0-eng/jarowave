@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#f8fafc; color:#64748b; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #e2e8f0;">
        Subscription Cancelled
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your Echo Link subscription has been cancelled
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $userName }}, your subscription has been cancelled.
    @if($accessEnd)
        You will continue to have access until {{ $accessEnd }}.
    @endif
</p>

---

{{-- ── WHAT THIS MEANS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:0 0 12px;">What happens next</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">1</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            @if($accessEnd)
                <strong style="color:#1a1a2e;">Until {{ $accessEnd }}</strong> — your SOS and emergency features remain active.
            @else
                <strong style="color:#1a1a2e;">Remaining period</strong> — your SOS and emergency features remain active until your current billing period ends.
            @endif
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fef2f2; border:1px solid #fecaca; border-radius:50%; font-size:11px; font-weight:800; color:#dc2626;">2</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">After access ends</strong> — your SOS button will be suspended and your household will no longer be protected on Echo Link.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:50%; font-size:11px; font-weight:800; color:#16a34a;">3</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Changed your mind?</strong> — you can reactivate your subscription at any time from your billing page.
        </td>
    </tr>
</table>

@component('mail::button', ['url' => config('app.account_url') . '/dashboard.html', 'color' => 'primary'])
Reactivate Subscription →
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
