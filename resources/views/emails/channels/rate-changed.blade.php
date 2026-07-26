@component('mail::message')

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
Billing rate updated for {{ $channel->name }}
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
Hi {{ $billingContact->name }}, your estate's per-household billing rate with Echo Link has changed.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
<tr>
<td style="padding:4px 0; font-size:13px; color:#888;">Rate Per Household</td>
<td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
R{{ number_format($oldAmountPerHousehold, 2) }} &rarr; R{{ number_format($newAmountPerHousehold, 2) }}
</td>
</tr>
<tr>
<td style="padding:4px 0; font-size:13px; color:#888;">Opted-In Households</td>
<td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $householdCount }}</td>
</tr>
</table>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
<tr>
<td style="padding:4px 0; font-size:14px; color:#888;">Your Current Monthly Total</td>
<td style="padding:4px 0; font-size:14px; font-weight:700; color:#1a1a2e; text-align:right;">R{{ number_format($oldTotalAmount, 2) }}</td>
</tr>
<tr>
<td style="padding:4px 0; font-size:14px; color:#888;">Your New Monthly Total</td>
<td style="padding:4px 0; font-size:15px; font-weight:800; color:#f97316; text-align:right;">R{{ number_format($newTotalAmount, 2) }}</td>
</tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
This new rate will apply from your next billing cycle. Each household's levy should be adjusted by R{{ number_format($newAmountPerHousehold - $oldAmountPerHousehold, 2) }} to reflect the change.
</p>

@component('mail::button', ['url' => config('app.url') . '/dashboard.html', 'color' => 'primary'])
View Billing Dashboard
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
Questions? Contact us at
<a href="mailto:billing@echolink.co.za" style="color:#f97316; text-decoration:none;">billing@echolink.co.za</a>
</p>

@endcomponent