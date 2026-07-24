@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fff7ed; color:#f97316; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fed7aa;">
        Account Linked
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    You're now linked to {{ $primary->name }}'s household
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $linkedAccount->name }}, your account is active and your alerts are now covered under this household.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Linked To</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $primary->name }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Alert Address</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">Now using {{ $primary->name }}'s registered address</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Billing</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#059669; text-align:right;">Covered by {{ $primary->name }}</td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    No action is needed from you - this is confirmation only. If you ever want to unlink your account, you can do this at any time from the Link Account section of the app.
</p>

@component('mail::button', ['url' => config('app.url') . '/dashboard.html', 'color' => 'primary'])
Open App
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:support@echolink.co.za" style="color:#f97316; text-decoration:none;">support@echolink.co.za</a>
</p>

@endcomponent