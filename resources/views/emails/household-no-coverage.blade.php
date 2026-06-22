{{-- resources/views/emails/household-no-coverage.blade.php --}}
@component('mail::message')
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fef2f2; color:#ef4444; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fecaca;">
        Account Paused
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your Echo Link access has been paused
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $user->name }}, your account has been paused because your new address does not currently have Echo Link coverage.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Account</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $user->name }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Email</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $user->email }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Status</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#ef4444; text-align:right;">
            Deactivated
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Reason</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            No Echo Link coverage at new address
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Subscription</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            Cancelled - no further charges
        </td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    If your area gains Echo Link coverage in future, or if you believe this was done in error, please contact your administrator to have your account reactivated.
</p>

@component('mail::button', ['url' => 'mailto:support@jaroworkspace.com', 'color' => 'primary'])
Contact Support
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:support@jaroworkspace.com" style="color:#f97316; text-decoration:none;">support@echolink.co.za</a>
</p>
@endcomponent