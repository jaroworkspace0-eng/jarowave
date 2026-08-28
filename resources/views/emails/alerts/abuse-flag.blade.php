@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fef2f2; color:#dc2626; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fecaca;">
        Flagged For Review
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    A household has been flagged for alert review
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    {{ $userName }}{{ $unitNumber ? ' (Unit ' . $unitNumber . ')' : '' }} has sent {{ $alertCount }} emergency alerts in the past 30 days.
</p>

---

{{-- ── NOTICE ── --}}
<div style="background:#fef3e2; border:1px solid #fed7aa; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#b45309; margin:0; font-weight:600;">⚠ This is a soft flag only — no alerts have been blocked or delayed. It's here for your visibility as the estate's billing contact.</p>
</div>

{{-- ── FLAG DETAILS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:0 0 12px;">Flag details</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Household</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $userName }}</td>
    </tr>
    @if($unitNumber)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Unit</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $unitNumber }}</td>
    </tr>
    @endif
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Alerts (last 30 days)</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#dc2626; text-align:right;">{{ $alertCount }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Flagged at</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $flaggedAtFormatted }}</td>
    </tr>
</table>

@component('mail::button', ['url' => $reviewUrl, 'color' => 'primary'])
Review Incident Reports →
@endcomponent

<p style="text-align:center; font-size:13px; color:#888; margin:16px 0 0;">
    If this activity looks legitimate, no action is needed — the flag can be cleared from the household's account page.
</p>

---

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    © {{ date('Y') }} Echo Link · JaroWorkspace ·
    <a href="https://policy.jaroworkspace.com" style="color:#f97316; text-decoration:none;">Privacy Policy</a>
</p>

@endcomponent