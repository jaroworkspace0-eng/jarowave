@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fff7ed; color:#f97316; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fed7aa;">
        Estate Billing Account
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    You're set up on Echo Link
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $user->name }}, your estate billing account has been created for <strong style="color:#1a1a2e;">{{ $channel->name }}</strong>.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Estate</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $channel->name }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Your Email</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $user->email }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Billing Model</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            Residential / Estate (Bulk EFT)
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Amount Per Household</td>
        <td style="padding:4px 0; font-size:18px; font-weight:800; color:#f97316; text-align:right;">
            R{{ number_format($channel->amount_per_household, 2) }}/month
        </td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    Click the button below to set your password and access<br>your estate billing dashboard.
</p>

@component('mail::button', ['url' => $resetLink, 'color' => 'primary'])
Set Your Password
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    This link expires in 60 minutes. If you did not expect this email, you can ignore it.<br>
    Questions? Contact us at
    <a href="mailto:billing@echolink.co.za" style="color:#f97316; text-decoration:none;">billing@echolink.co.za</a>
</p>

@endcomponent