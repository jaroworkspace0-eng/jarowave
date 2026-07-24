@component('mail::message')

<div style="text-align:center; margin-bottom:8px;">
    <span style="display:inline-block; background:#fef2f2; color:#dc2626; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px 14px; border-radius:100px; border:1px solid #fecaca;">
        Link Declined
    </span>
</div>

<h1 style="text-align:center; font-size:22px; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; margin:12px 0 4px;">
    Your link request for {{ $linkedAccount->name }} wasn't approved
</h1>

<p style="text-align:center; font-size:14px; color:#888; margin:0 0 28px;">
    Hi {{ $primary->name }}, this request was reviewed and declined. No changes have been made to your account.
</p>

---

<p style="font-size:13px; color:#888; text-align:center; margin:16px 0;">
    If you believe this was declined in error, or you'd like more information, contact us and we can look into it.
</p>

@component('mail::button', ['url' => config('app.url') . '/dashboard.html', 'color' => 'primary'])
Open App
@endcomponent

<p style="font-size:12px; color:#ccc; text-align:center; margin-top:24px;">
    Questions? Contact us at
    <a href="mailto:support@echolink.co.za" style="color:#f97316; text-decoration:none;">support@echolink.co.za</a>
</p>

@endcomponent