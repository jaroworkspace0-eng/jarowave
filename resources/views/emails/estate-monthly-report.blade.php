@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fff7ed; color:#f97316; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fed7aa;">
        Monthly Report
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your {{ $periodLabel }} safety report is ready
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    {{ $channelName }} — summary attached as PDF, key numbers below.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Incidents</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $data['incidents']['total'] }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Resolved</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $data['incidents']['resolved'] }} / {{ $data['incidents']['total'] }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Patrol Coverage</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#f97316; text-align:right;">
            {{ $data['patrol']['coverage_pct'] !== null ? $data['patrol']['coverage_pct'].'%' : '—' }}
        </td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Active Households</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $data['households']['active_households'] }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0; font-size:13px; color:#888;">Open Tickets</td>
        <td style="padding:4px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $data['tickets']['open'] }}</td>
    </tr>
</table>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    Full breakdown — response times, patrol scans by patroller, and ticket resolution — is in the attached PDF and on your live dashboard.
</p>

@component('mail::button', ['url' => config('app.url') . '/estate/analytics', 'color' => 'primary'])
View Full Dashboard
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:billing@echolink.co.za" style="color:#f97316; text-decoration:none;">billing@echolink.co.za</a>
</p>

@endcomponent