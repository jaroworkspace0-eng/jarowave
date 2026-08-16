@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fef2f2; color:#dc2626; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fecaca;">
        Account Unlinked
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your account has been unlinked
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $linkedAccount->name }}, your account is no longer linked to a household. Your coverage under that household has ended.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Alert Address</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">Removed — please add your own</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Billing</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#dc2626; text-align:right;">No longer covered</td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    To keep receiving coverage, add your own address and set up your own subscription from the app.
</p>

@component('mail::button', ['url' => config('app.url') . '/dashboard.html', 'color' => 'primary'])
Update My Account
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:support@echolink.co.za" style="color:#f97316; text-decoration:none;">support@jaroworkspace.co.za</a>
</p>

@endcomponent