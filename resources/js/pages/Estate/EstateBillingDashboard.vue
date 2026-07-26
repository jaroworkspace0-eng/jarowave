<script setup lang="ts">
import { useAuthStore } from '@/stores/auth';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { AlertTriangle, Ban, CheckCircle, Clock } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'estate_billing') {
        router.visit('/dashboard');
    }
});

// ── Types ─────────────────────────────────────────────────────────────────
interface ChannelSubscription {
    id: number;
    household_count: number;
    amount_per_household: number;
    linked_account_count: number;
    amount_per_linked_account: number | null;
    total_amount: number;
    status: 'pending' | 'active' | 'overdue' | 'cancelled';
    billing_model: string;
    current_period_start: string | null;
    current_period_end: string | null;
    paid_at: string | null;
}

interface Household {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    unit_number: string | null;
    subscription_status: string;
}

interface Payment {
    id: number;
    amount: number;
    household_count: number;
    payment_method: string;
    status: string;
    merchant_reference: string | null;
    paid_at: string | null;
    created_at: string;
}

interface Channel {
    id: number;
    name: string;
    billing_model: string;
}

// ── State ─────────────────────────────────────────────────────────────────
const channel = ref<Channel | null>(null);
const summary = ref<ChannelSubscription | null>(null);
const households = ref<Household[]>([]);
const payments = ref<Payment[]>([]);
const isLoading = ref(true);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

// EFT modal
const showEftModal = ref(false);
const eftForm = ref({ amount: '', note: '', proof: null as File | null });
const isSubmittingEft = ref(false);
const eftRef = ref('');
const copiedRef = ref(false);

// Pay Now
const isPayingNow = ref(false);

// Remove household modal
const showRemoveModal = ref(false);
const householdToRemove = ref<Household | null>(null);
const isRemoving = ref(false);

// Tabs
const activeTab = ref<'households' | 'payments'>('households');

// ── Helpers ───────────────────────────────────────────────────────────────
const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

const showFlash = (msg: string, type: 'success' | 'error' = 'success') => {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 6000);
};

const fmt = (val: number | null | undefined) =>
    val != null
        ? `R${Number(val).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
        : '—';

const formatDate = (d: string | null) =>
    d
        ? new Date(d).toLocaleDateString('en-ZA', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const generateEftRef = () => {
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const seq = String(Math.floor(Math.random() * 900) + 100);
    return `ECL-EST-${yyyy}-${mm}-${seq}`;
};

const copyToClipboard = async (text: string, cb: () => void) => {
    try {
        await navigator.clipboard.writeText(text);
        cb();
    } catch {
        const el = document.createElement('textarea');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        cb();
    }
};

const copyEftRef = () => {
    copyToClipboard(eftRef.value, () => {
        copiedRef.value = true;
        setTimeout(() => (copiedRef.value = false), 2000);
    });
};

// ── Computed ──────────────────────────────────────────────────────────────

// Days remaining until the current period ends. Negative once the period
// has actually passed — callers must not display this raw.
const daysUntilDue = computed(() => {
    if (!summary.value?.current_period_end) return null;
    const diff =
        new Date(summary.value.current_period_end).getTime() - Date.now();
    return Math.ceil(diff / (1000 * 60 * 60 * 24));
});

// True once the period has passed without payment — regardless of whether
// the backend has flipped status to 'overdue' yet, so the UI never shows
// a negative "days remaining" figure.
const isPastDue = computed(
    () =>
        daysUntilDue.value !== null &&
        daysUntilDue.value < 0 &&
        summary.value?.status !== 'active' &&
        summary.value?.status !== 'cancelled',
);

const isDueSoon = computed(
    () =>
        daysUntilDue.value !== null &&
        daysUntilDue.value >= 0 &&
        daysUntilDue.value <= 3 &&
        summary.value?.status !== 'cancelled',
);

const statusConfig = computed(() => {
    // Treat a past-due period as overdue for display purposes even if the
    // backend status field hasn't transitioned yet.
    const effectiveStatus = isPastDue.value ? 'overdue' : summary.value?.status;

    switch (effectiveStatus) {
        case 'active':
            return { label: 'Active', cls: 'status-active', icon: CheckCircle };
        case 'pending':
            return {
                label: 'Pending Payment',
                cls: 'status-pending',
                icon: Clock,
            };
        case 'overdue':
            return {
                label: 'Overdue',
                cls: 'status-overdue',
                icon: AlertTriangle,
            };
        case 'cancelled':
            return { label: 'Cancelled', cls: 'status-cancelled', icon: Ban };
        default:
            return { label: '—', cls: '', icon: Clock };
    }
});

// Full billed total this period, including linked accounts — not just
// household_count × amount_per_household.
const linkedAccountTotal = computed(() => {
    if (
        !summary.value?.linked_account_count ||
        !summary.value?.amount_per_linked_account
    ) {
        return 0;
    }
    return (
        summary.value.linked_account_count *
        summary.value.amount_per_linked_account
    );
});

// ── Data ──────────────────────────────────────────────────────────────────
const base = () =>
    `${import.meta.env.VITE_APP_URL}/api/channels/${channel.value!.id}/billing`;

const fetchAll = async () => {
    isLoading.value = true;
    try {
        const [sumRes, hhRes, payRes] = await Promise.all([
            axios.get(`${base()}/summary`, getHeaders()),
            axios.get(`${base()}/opted-in-households`, getHeaders()),
            axios.get(`${base()}/payment-history`, getHeaders()),
        ]);
        summary.value = sumRes.data.channel_subscription;
        households.value = hhRes.data.households;
        payments.value = payRes.data.payments.data ?? payRes.data.payments;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load billing data.',
            'error',
        );
    } finally {
        isLoading.value = false;
    }
};

onMounted(async () => {
    try {
        const res = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/estate/my-channel`,
            getHeaders(),
        );
        channel.value = res.data.channel;
        await fetchAll();
    } catch (err: any) {
        showFlash('Failed to load channel.', 'error');
        isLoading.value = false;
    }
});

// ── EFT Payment ───────────────────────────────────────────────────────────
const openEftModal = () => {
    eftRef.value = generateEftRef();
    eftForm.value = {
        amount: String(summary.value?.total_amount ?? ''),
        note: '',
        proof: null,
    };
    copiedRef.value = false;
    showEftModal.value = true;
};

const onProofSelected = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    eftForm.value.proof = file;
};

const submitEft = async () => {
    if (!eftForm.value.proof || !eftForm.value.note) return;
    isSubmittingEft.value = true;

    const fd = new FormData();
    fd.append('amount', eftForm.value.amount);
    fd.append('note', eftForm.value.note);
    fd.append('proof', eftForm.value.proof);

    try {
        await axios.post(`${base()}/mark-eft-paid`, fd, {
            headers: {
                ...getHeaders().headers,
                'Content-Type': 'multipart/form-data',
            },
        });
        showFlash(
            'EFT payment submitted. All opted-in households will be activated shortly.',
        );
        showEftModal.value = false;
        await fetchAll();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to submit payment.',
            'error',
        );
    } finally {
        isSubmittingEft.value = false;
    }
};

// ── Pay Now (PayFast) ────────────────────────────────────────────────────
const submitPayFastForm = (action: string, fields: Record<string, string>) => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = action;
    Object.entries(fields).forEach(([key, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
};

const payNow = async () => {
    if (!channel.value) return;
    isPayingNow.value = true;
    try {
        const res = await axios.post(`${base()}/pay-now`, {}, getHeaders());
        submitPayFastForm(res.data.action, res.data.fields);
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to start payment.',
            'error',
        );
    } finally {
        isPayingNow.value = false;
    }
};

// ── Remove Household ──────────────────────────────────────────────────────
const confirmRemove = (household: Household) => {
    householdToRemove.value = household;
    showRemoveModal.value = true;
};

const removeHousehold = async () => {
    if (!householdToRemove.value) return;
    isRemoving.value = true;

    try {
        await axios.post(
            `${base()}/remove-household`,
            { user_id: householdToRemove.value.id },
            getHeaders(),
        );

        showFlash(
            `${householdToRemove.value.name} has been removed from estate billing.`,
        );
        showRemoveModal.value = false;
        householdToRemove.value = null;
        await fetchAll();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to remove household.',
            'error',
        );
    } finally {
        isRemoving.value = false;
    }
};

// Payment filters
const paySearch = ref('');
const payStatusFilter = ref('all');
const payDateFrom = ref('');
const payDateTo = ref('');

const hasPayFilters = computed(
    () =>
        payStatusFilter.value !== 'all' ||
        paySearch.value ||
        payDateFrom.value ||
        payDateTo.value,
);

const clearPayFilters = () => {
    paySearch.value = '';
    payStatusFilter.value = 'all';
    payDateFrom.value = '';
    payDateTo.value = '';
};

const filteredPayments = computed(() => {
    let result = [...payments.value];

    if (payStatusFilter.value !== 'all') {
        result = result.filter((p) => p.status === payStatusFilter.value);
    }

    if (paySearch.value.trim()) {
        const q = paySearch.value.toLowerCase();
        result = result.filter((p) =>
            p.merchant_reference?.toLowerCase().includes(q),
        );
    }

    if (payDateFrom.value) {
        const from = new Date(payDateFrom.value);
        result = result.filter((p) => new Date(p.created_at) >= from);
    }

    if (payDateTo.value) {
        const to = new Date(payDateTo.value);
        to.setHours(23, 59, 59);
        result = result.filter((p) => new Date(p.created_at) <= to);
    }

    return result;
});
</script>
