@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#f0fdf4; color:#059669; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #a7f3d0;">
        Link Approved
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    {{ $linkedAccount->name }} is now linked to your household
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $primary->name }}, this account is now active and using your registered address for alerts.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Linked Account</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $linkedAccount->name }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Status</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#059669; text-align:right;">Active</td>
    </tr>
    @if($newMonthlyAmount !== null)
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Your New Monthly Amount</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#f97316; text-align:right;">R{{ number_format($newMonthlyAmount, 2) }}/month</td>
    </tr>
    @endif
</table>

---

@if($isEstateBilled)
<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    This household is billed through your estate's bulk billing — there's nothing further for you to pay or set up.
</p>
@elseif($newMonthlyAmount !== null)
<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    Your subscription has been updated automatically and will charge R{{ number_format($newMonthlyAmount, 2) }} from your next billing date, using your existing payment method. No action is needed from you.
</p>
@else
<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    No action is needed from you - this is confirmation only.
</p>
@endif

@component('mail::button', ['url' => config('app.url') . '/dashboard.html', 'color' => 'primary'])
View Household
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:billing@echolink.co.za" style="color:#f97316; text-decoration:none;">billing@echolink.co.za</a>
</p>

@endcomponent