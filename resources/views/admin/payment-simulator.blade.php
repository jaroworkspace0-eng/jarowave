<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Payment Simulator — Echo Link</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 100px; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; }
        .log-entry { padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .log-entry:last-child { border-bottom: none; }
        select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

{{-- @if(app()->environment('production'))
<div class="min-h-screen flex items-center justify-center">
    <div class="bg-white border border-red-200 rounded-2xl p-10 text-center max-w-md">
        <div class="w-14 h-14 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <h2 class="text-lg font-bold text-gray-900 mb-2">Not available in production</h2>
        <p class="text-sm text-gray-500">The payment simulator is disabled in production environments.</p>
    </div>
</div>
@else --}}

<div class="max-w-3xl mx-auto py-12 px-6">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-10">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <div class="w-8 h-8 bg-orange-50 border border-orange-200 rounded-lg flex items-center justify-center">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                </div>
                <span class="text-xs font-bold text-orange-500 uppercase tracking-widest">Dev Tool</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Payment Simulator</h1>
            <p class="text-sm text-gray-400 mt-1">Simulate PayFast / Ozow webhook events without real transactions.</p>
        </div>
        <div class="badge bg-amber-50 text-amber-600 border border-amber-200">
            {{ strtoupper(app()->environment()) }}
        </div>
    </div>

    {{-- User selector --}}
    <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-6">
        <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Household member to simulate</label>
        <select id="user-select" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-800 bg-white focus:outline-none focus:ring-2 focus:ring-orange-400 focus:border-transparent pr-10">
            <option value="">Select a household member...</option>
            @foreach($users as $user)
                <option value="{{ $user->id }}"
                    data-email="{{ $user->email }}"
                    data-name="{{ $user->name }}"
                    data-status="{{ $user->subscription?->status ?? 'none' }}">
                    {{ $user->name }} — {{ $user->email }}
                    @if($user->subscription) ({{ $user->subscription->status }}) @endif
                </option>
            @endforeach
        </select>

        {{-- Selected user info --}}
        <div id="user-info" class="hidden mt-4 grid grid-cols-3 gap-3">
            <div class="bg-gray-50 rounded-xl p-3">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">Name</div>
                <div id="info-name" class="text-sm font-600 text-gray-800">—</div>
            </div>
            <div class="bg-gray-50 rounded-xl p-3">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">Email</div>
                <div id="info-email" class="text-sm font-600 text-gray-800 truncate">—</div>
            </div>
            <div class="bg-gray-50 rounded-xl p-3">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">Sub status</div>
                <div id="info-status" class="text-sm font-600 text-gray-800">—</div>
            </div>
        </div>
    </div>

    {{-- Simulation actions --}}
    <div class="grid grid-cols-1 gap-3 mb-6">

        {{-- Complete --}}
        <div class="bg-white border border-gray-200 rounded-2xl p-5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-green-50 border border-green-200 rounded-xl flex items-center justify-center flex-shrink-0">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-0.5">
                        <span class="badge bg-green-50 text-green-700 border border-green-200">COMPLETE</span>
                        <span class="text-sm font-semibold text-gray-900">Payment Successful</span>
                    </div>
                    <p class="text-xs text-gray-400">Activates subscription · queues success email · re-enables SOS instantly</p>
                </div>
            </div>
            <button onclick="simulate('complete')"
                class="flex-shrink-0 bg-green-600 hover:bg-green-700 active:scale-95 text-white text-xs font-bold px-5 py-2.5 rounded-xl transition-all">
                Run
            </button>
        </div>

        {{-- Failed --}}
        <div class="bg-white border border-gray-200 rounded-2xl p-5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-red-50 border border-red-200 rounded-xl flex items-center justify-center flex-shrink-0">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-0.5">
                        <span class="badge bg-red-50 text-red-700 border border-red-200">FAILED</span>
                        <span class="text-sm font-semibold text-gray-900">Payment Failed</span>
                    </div>
                    <p class="text-xs text-gray-400">Sets past_due · starts 24h grace period · sends push notification + email</p>
                </div>
            </div>
            <button onclick="simulate('failed')"
                class="flex-shrink-0 bg-red-600 hover:bg-red-700 active:scale-95 text-white text-xs font-bold px-5 py-2.5 rounded-xl transition-all">
                Run
            </button>
        </div>

        {{-- Suspended --}}
        <div class="bg-white border border-gray-200 rounded-2xl p-5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-red-50 border border-red-200 rounded-xl flex items-center justify-center flex-shrink-0">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#991b1b" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-0.5">
                        <span class="badge bg-red-50 text-red-800 border border-red-300">SUSPENDED</span>
                        <span class="text-sm font-semibold text-gray-900">Grace Period Expired</span>
                    </div>
                    <p class="text-xs text-gray-400">Skips grace period · suspends SOS immediately · sends push notification</p>
                </div>
            </div>
            <button onclick="simulate('suspended')"
                class="flex-shrink-0 bg-red-900 hover:bg-red-800 active:scale-95 text-white text-xs font-bold px-5 py-2.5 rounded-xl transition-all">
                Run
            </button>
        </div>

        {{-- Cancelled --}}
        <div class="bg-white border border-gray-200 rounded-2xl p-5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-gray-50 border border-gray-200 rounded-xl flex items-center justify-center flex-shrink-0">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-0.5">
                        <span class="badge bg-gray-100 text-gray-600 border border-gray-200">CANCELLED</span>
                        <span class="text-sm font-semibold text-gray-900">Subscription Cancelled</span>
                    </div>
                    <p class="text-xs text-gray-400">Sets cancelled · queues cancellation email · SOS suspends at period end</p>
                </div>
            </div>
            <button onclick="simulate('cancelled')"
                class="flex-shrink-0 bg-gray-600 hover:bg-gray-700 active:scale-95 text-white text-xs font-bold px-5 py-2.5 rounded-xl transition-all">
                Run
            </button>
        </div>

        {{-- Resolved --}}
        <div class="bg-white border border-gray-200 rounded-2xl p-5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-blue-50 border border-blue-200 rounded-xl flex items-center justify-center flex-shrink-0">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><path d="M23 7l-7 5-7-5-7 5"/><path d="M1 7l7 5 7-5 7 5"/><rect x="1" y="7" width="22" height="13" rx="2"/></svg>
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-0.5">
                        <span class="badge bg-blue-50 text-blue-700 border border-blue-200">RESOLVED</span>
                        <span class="text-sm font-semibold text-gray-900">Payment Resolved</span>
                    </div>
                    <p class="text-xs text-gray-400">Clears all failures · reactivates subscription · re-enables SOS instantly</p>
                </div>
            </div>
            <button onclick="simulate('resolved')"
                class="flex-shrink-0 bg-blue-600 hover:bg-blue-700 active:scale-95 text-white text-xs font-bold px-5 py-2.5 rounded-xl transition-all">
                Run
            </button>
        </div>

    </div>

    {{-- Response log --}}
    <div class="bg-gray-900 rounded-2xl overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-800">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Response log</span>
            <button onclick="clearLog()" class="text-xs text-gray-600 hover:text-gray-400 transition">Clear</button>
        </div>
        <div id="log" class="px-5 py-4 min-h-28 font-mono text-xs space-y-2">
            <span class="text-gray-600">No simulations run yet...</span>
        </div>
    </div>

    <p class="text-center text-xs text-gray-300 mt-6">
        Echo Link Payment Simulator · {{ app()->environment() }} environment · Not available in production
    </p>

</div>

{{-- Loading overlay --}}
<div id="loading" class="hidden fixed inset-0 bg-black/20 flex items-center justify-center z-50">
    <div class="bg-white rounded-2xl px-8 py-6 flex items-center gap-4 shadow-xl">
        <svg class="animate-spin w-5 h-5 text-orange-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
        </svg>
        <span class="text-sm font-semibold text-gray-700">Simulating...</span>
    </div>
</div>

<script>
const statusColors = {
    complete:  { dot: '#16a34a', label: 'text-green-400' },
    failed:    { dot: '#dc2626', label: 'text-red-400'   },
    suspended: { dot: '#991b1b', label: 'text-red-300'   },
    cancelled: { dot: '#6b7280', label: 'text-gray-400'  },
    resolved:  { dot: '#2563eb', label: 'text-blue-400'  },
};

document.getElementById('user-select').addEventListener('change', function () {
    const opt    = this.options[this.selectedIndex];
    const info   = document.getElementById('user-info');
    if (!this.value) { info.classList.add('hidden'); return; }
    document.getElementById('info-name').textContent   = opt.dataset.name   || '—';
    document.getElementById('info-email').textContent  = opt.dataset.email  || '—';
    document.getElementById('info-status').textContent = opt.dataset.status || 'none';
    info.classList.remove('hidden');
});

async function simulate(type) {
    const select = document.getElementById('user-select');
    const userId = select.value;
    if (!userId) { alert('Please select a household member first.'); return; }

    const opt       = select.options[select.selectedIndex];
    const userName  = opt.dataset.name;
    const userEmail = opt.dataset.email;
    const log       = document.getElementById('log');
    const loading   = document.getElementById('loading');
    const colors    = statusColors[type] || { dot: '#888', label: 'text-gray-400' };

    loading.classList.remove('hidden');

    const time = new Date().toLocaleTimeString();

    try {
        const res = await fetch('/admin/simulate-payment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ type, user_id: userId, user_name: userName, user_email: userEmail }),
        });

        const data = await res.json();
        const ok   = data.success;

        const entry = document.createElement('div');
        entry.className = 'log-entry';
        entry.innerHTML = `
            <div class="flex items-start gap-2">
                <span class="mt-1 w-2 h-2 rounded-full flex-shrink-0" style="background:${ok ? colors.dot : '#dc2626'}; margin-top:5px;"></span>
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <span class="${ok ? colors.label : 'text-red-400'} font-bold">${type.toUpperCase()}</span>
                        <span class="text-gray-600">${time}</span>
                        <span class="text-gray-500">→ ${ok ? 'OK' : 'FAILED'}</span>
                    </div>
                    <div class="text-gray-500 mt-1">User: ${data.user || userName} · Sub: ${data.subscription || '—'}</div>
                    ${data.results ? `<div class="text-gray-600 mt-1">${JSON.stringify(data.results)}</div>` : ''}
                    ${data.error ? `<div class="text-red-400 mt-1">${data.error}</div>` : ''}
                </div>
            </div>`;

        // Clear placeholder and prepend
        if (log.querySelector('span.text-gray-600')) log.innerHTML = '';
        log.prepend(entry);

    } catch (e) {
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        entry.innerHTML = `<span class="text-red-400">[${time}] Network error: ${e.message}</span>`;
        if (log.querySelector('span.text-gray-600')) log.innerHTML = '';
        log.prepend(entry);
    } finally {
        loading.classList.add('hidden');
    }
}

function clearLog() {
    document.getElementById('log').innerHTML = '<span class="text-gray-600">Log cleared.</span>';
}
</script>

@endif
</body>
</html>