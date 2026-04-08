@component('mail::message')

{{-- ── BADGE ── --}}
<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fff7ed; color:#f97316; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fed7aa;">
        Welcome to Echo Link
    </span>
</div>

{{-- ── HEADING ── --}}
<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    You're in. Your community has your back.
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $user->name }}, your Echo Link account has been created and you are now connected to <strong style="color:#1a1a2e;">{{ $organisationName }}</strong>.
</p>

---

{{-- ── ACCOUNT DETAILS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:0 0 12px;">Your account details</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Name</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ $user->name }}</td>
    </tr>
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
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Watch Group</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#f97316; text-align:right;">{{ $organisationName }}</td>
    </tr>
    @if($adminAdded && $tempPassword)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Temporary Password</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right; font-family:monospace;">{{ $tempPassword }}</td>
    </tr>
    @endif
</table>

@if($adminAdded && $tempPassword)
<div style="background:#fef3e2; border:1px solid #fed7aa; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#b45309; margin:0; font-weight:600;">⚠ Please change your password after your first login in Echo Link App.</p>
</div>
@endif

---

{{-- ── TRIAL & BILLING ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:24px 0 12px;">Your subscription</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Monthly fee</td>
        <td style="padding:6px 0; font-size:18px; font-weight:800; color:#f97316; text-align:right;">R80/month</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Free trial</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">30 days — no charge</td>
    </tr>
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">First charge</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ now()->addDays(30)->format('d M Y') }}</td>
    </tr>
    @if(!$adminAdded)
    <tr>
        <td style="padding:6px 0; font-size:13px; color:#888;">Payment via</td>
        <td style="padding:6px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-align:right;">{{ ucfirst($gateway) }}</td>
    </tr>
    @endif
</table>

@if($adminAdded)
<div style="background:#eff4ff; border:1px solid #bfdbfe; border-radius:10px; padding:14px 16px; margin-bottom:24px;">
    <p style="font-size:13px; color:#1d4ed8; margin:0;">
        💳 <strong>Action required:</strong> Please visit your account dashboard to add a payment method before your 30-day trial ends to avoid losing access.
    </p>
</div>
@endif

---

{{-- ── NEXT STEPS ── --}}
<p style="font-size:13px; font-weight:700; color:#1a1a2e; margin:24px 0 16px;">What to do next</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">1</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Download the Echo Link app</strong> — this is how you trigger emergency alerts and stay connected to your patrol group 24/7.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">2</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Sign in with your email and password</strong> — use the same credentials you registered with on this email.
        </td>
    </tr>
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">3</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Update your address immediately</strong> — go to Profile → Edit Address in the app. Your address is how responders find you during an emergency. An incorrect or missing address can delay help.
        </td>
    </tr>
    @if($adminAdded)
    <tr>
        <td style="padding:8px 0; vertical-align:top; width:28px;">
            <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#fff7ed; border:1px solid #fed7aa; border-radius:50%; font-size:11px; font-weight:800; color:#f97316;">4</span>
        </td>
        <td style="padding:8px 0; font-size:13px; color:#444; vertical-align:top;">
            <strong style="color:#1a1a2e;">Add your payment method</strong> — visit your account dashboard and add your card or bank details before your trial ends.
        </td>
    </tr>
    @endif
</table>

{{-- ── APP DOWNLOAD BUTTON ── --}}
@component('mail::button', ['url' => 'https://play.google.com/store/apps/details?id=com.echolink', 'color' => 'primary'])
Download Echo Link on Google Play
@endcomponent

{{-- ── DASHBOARD LINK ── --}}
<p style="text-align:center; font-size:13px; color:#888; margin:16px 0 0;">
    Manage your subscription at
    <a href="https://account.jaroworkspace.com/login.html" style="color:#f97316; text-decoration:none; font-weight:600;">account.jaroworkspace.com</a>
</p>

---

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:support@jaroworkspace.com" style="color:#f97316; text-decoration:none;">support@jaroworkspace.com</a>
</p>

@endcomponent
