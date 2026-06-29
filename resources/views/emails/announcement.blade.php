@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:{{ $badgeBg }}; color:{{ $badgeColor }}; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid {{ $badgeBorder }};">
        {{ $typeLabel }}
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    {{ $announcement->title }}
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $recipientName }},
</p>

<p style="text-align:center; font-size:12px; color:#aaa; margin:0 0 24px; font-weight:600;">
    From: {{ $announcement->department }}
</p>

---

<p style="font-size:14px; color:#444; line-height:1.6; margin:0 0 24px; white-space:pre-line;">{{ $announcement->message }}</p>

@if($announcement->type === 'payment' && $announcement->payment_subtype)
<div style="background:#fef3e2; border:1px solid #fed7aa; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#b45309; margin:0; font-weight:600;">
        Status: {{ $paymentSubtypeLabel }}
    </p>
</div>
@endif

@if($announcement->type === 'update_app')
---

<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:24px 0 12px;">Update available</p>

@if($announcement->app_version)
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 20px;">
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">New version</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $announcement->app_version }}</td>
    </tr>
</table>
@endif

@component('mail::button', ['url' => $announcement->playstore_url, 'color' => 'primary'])
Update Now
@endcomponent
@endif

@if($announcement->type === 'urgent')
<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#b91c1c; margin:0; font-weight:600;">This is an urgent announcement — please action it promptly.</p>
</div>
@endif

<p style="text-align:center; font-size:13px; color:#888; margin:24px 0 0;">
    Manage your account at
    <a href="https://account.jaroworkspace.com/login.html" style="color:#f97316; text-decoration:none; font-weight:600;">account.jaroworkspace.com</a>
</p>

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:support@jaroworkspace.com" style="color:#f97316; text-decoration:none;">support@jaroworkspace.com</a>
</p>

@endcomponent