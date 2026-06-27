@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fff7ed; color:#f97316; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fed7aa;">
        {{ $adminCreated ? 'Account Created' : 'Welcome Aboard' }}
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    {{ $adminCreated ? 'Your Echo Link account is ready' : 'Your organisation is live on Echo Link' }}
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $user->name }}, welcome to Echo Link.
    @if($adminCreated)
        Your account has been set up by the Echo Link team.
    @else
        Your organisation account has been created and you're ready to get started.
    @endif
</p>

---

{{-- ── ACCOUNT DETAILS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:0 0 12px;">Your account details</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Name</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $user->name }}</td>
    </tr>
    @if($user->organisation_name)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Organisation</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#f97316; text-align:right;">{{ $user->organisation_name }}</td>
    </tr>
    @endif
    @if($user->organisation_type)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Type</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">
            {{ $user->organisation_type === 'estate' ? 'Estate / Complex' : 'Neighbourhood Watch' }}
        </td>
    </tr>
    @endif
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Email</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $user->email }}</td>
    </tr>
    @if($user->phone)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Phone</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $user->phone }}</td>
    </tr>
    @endif
    @if($adminCreated && $tempPassword)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Temporary Password</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right; font-family:monospace;">{{ $tempPassword }}</td>
    </tr>
    @endif
</table>

@if($adminCreated && $tempPassword)
<div style="background:#fef3e2; border:1px solid #fed7aa; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#b45309; margin:0; font-weight:600;">Please change your password after your first login.</p>
</div>
@endif

---

{{-- ── HOW IT WORKS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:24px 0 12px;">How Echo Link works for you</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 12px;">
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">{{ $user->organisation_type === 'estate' ? 'Unit fee' : 'Household fee' }}</td>
        <td style="padding:6px 0; font-size:18px; font-weight:800; color:#f97316; text-align:right;">
            R80/{{ $user->organisation_type === 'estate' ? 'unit' : 'household' }}/month
        </td>
    </tr>
    {{-- <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Payouts</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">1st of every month</td>
    </tr> --}}
</table>

<p style="font-size:12px; color:#888; margin:0 0 24px;">
    * R80 is the default monthly fee per {{ $user->organisation_type === 'estate' ? 'unit' : 'household' }}. You can set a custom amount per channel from your dashboard — your earnings are paid out monthly based on active subscriptions.
</p>

---

{{-- ── NEXT STEPS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:24px 0 16px;">What to do next</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">1</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Sign in to your dashboard</strong> — manage your channels, personnel and households from your admin panel.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">2</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Set up your channels</strong> — create communication channels per zone, shift or patrol area.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">3</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Add your personnel</strong> — invite your security team and patrol members to Echo Link.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">4</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Generate invite links</strong> — share your household invite links to start onboarding residents and earning monthly payouts.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">5</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Download the Echo Link app</strong> — your personnel and you need the app for push-to-talk and emergency alerts.
        </td>
    </tr>
</table>

@component('mail::button', ['url' => config('app.url') . '/dashboard', 'color' => 'primary'])
Go to Your Dashboard →
@endcomponent

<p style="text-align:center; font-size:13px; color:#888; margin:16px 0 0;">
    Need help getting started? Email us at
    <a href="mailto:support@jaroworkspace.com" style="color:#f97316; text-decoration:none; font-weight:600;">support@jaroworkspace.com</a>
</p>

---

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    © {{ date('Y') }} Echo Link · JaroWorkspace ·
    <a href="https://policy.jaroworkspace.com" style="color:#f97316; text-decoration:none;">Privacy Policy</a>
</p>

@endcomponent