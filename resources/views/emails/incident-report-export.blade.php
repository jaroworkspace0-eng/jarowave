@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#eff6ff; color:#2563eb; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #bfdbfe;">
        Incident Report Export
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; margin:12px 0 4px;">
    Your report export is attached
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    The incident reports for the selected period have been compiled and attached to this email.
</p>

---

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:24px;">
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Period</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $dateFrom }} → {{ $dateTo }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Total reports</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $total }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Formats</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ implode(', ', array_map('strtoupper', $formats)) }}</td>
    </tr>
</table>

<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#15803d; margin:0; font-weight:600;">
        ✓ {{ count($formats) > 1 ? 'Files are' : 'File is' }} attached to this email. Please keep this report confidential and handle according to your data protection policy.
    </p>
</div>

<p style="font-size:12px; color:#94a3b8; text-align:center; margin-top:24px;">
    © {{ date('Y') }} Echo Link · JaroWorkspace · Confidential
</p>

@endcomponent