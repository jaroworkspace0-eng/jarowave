<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    adminId: { type: Number, required: true },
    channels: { type: Array, default: () => [] }, // [{ id, name }]
    households: { type: Array, default: () => [] }, // [{ id, name }]
});

// allScopes holds every admin's claims, not just this admin's — required
// so the picker can lock out anything already claimed by someone else.
const allScopes = ref([]);
const scopeType = ref('channel');
const scopeId = ref('');
const loading = ref(false);

// This admin's own claims — the list rendered below.
const myScopes = computed(() =>
    allScopes.value.filter((s) => s.admin_id === props.adminId),
);

// Full option list for the currently selected type (channel/household).
const optionsForType = computed(() =>
    scopeType.value === 'channel' ? props.channels : props.households,
);

// Anything already claimed by ANY admin (including this one) is removed
// from the "add" dropdown — already-mine shouldn't be re-addable, and
// someone else's claim must be locked out entirely.
const availableOptions = computed(() => {
    const claimedIds = new Set(
        allScopes.value
            .filter((s) => s.scope_type === scopeType.value)
            .map((s) => s.scope_id),
    );
    return optionsForType.value.filter((o) => !claimedIds.has(o.id));
});

async function loadScopes() {
    loading.value = true;
    // NOTE: intentionally not filtering by admin_id here — this needs the
    // full set of claims across all admins to enforce exclusivity. If the
    // backend currently only returns scopes for a given admin_id, this
    // endpoint (or a param on it) needs to support returning everyone's.
    const { data } = await axios.get('/api/admin/alert-scopes');
    allScopes.value = data;
    loading.value = false;
}

async function addScope() {
    if (!scopeId.value) return;
    const { data } = await axios.post('/api/admin/alert-scopes', {
        admin_id: props.adminId,
        scope_type: scopeType.value,
        scope_id: Number(scopeId.value),
    });
    allScopes.value.push(data);
    scopeId.value = '';
}

async function removeScope(scope) {
    await axios.delete(`/api/admin/alert-scopes/${scope.id}`);
    allScopes.value = allScopes.value.filter((s) => s.id !== scope.id);
}

function labelFor(scope) {
    const list =
        scope.scope_type === 'channel' ? props.channels : props.households;
    return (
        list.find((item) => item.id === scope.scope_id)?.name ||
        `#${scope.scope_id}`
    );
}

onMounted(loadScopes);
</script>

<template>
    <div class="asm-panel">
        <p class="asm-title">Exclusive alert claims</p>
        <p class="asm-hint">
            Claiming a channel/household here routes its alerts to this admin
            only — everyone else stops hearing them. This admin still hears
            every other (unclaimed) alert as normal. Once claimed, no other
            admin can claim the same channel/household.
        </p>

        <div class="asm-add-row">
            <select v-model="scopeType" class="asm-select">
                <option value="channel">Channel</option>
                <option value="household">Household</option>
            </select>
            <select v-model="scopeId" class="asm-select asm-select--wide">
                <option value="" disabled>Select…</option>
                <option v-for="o in availableOptions" :key="o.id" :value="o.id">
                    {{ o.name }}
                </option>
            </select>
            <button type="button" class="asm-add-btn" @click="addScope">
                Claim
            </button>
        </div>
        <p v-if="!availableOptions.length" class="asm-hint">
            All {{ scopeType }}s are already claimed by an admin.
        </p>

        <p v-if="loading" class="asm-hint">Loading…</p>
        <ul v-else class="asm-list">
            <li v-for="s in myScopes" :key="s.id" class="asm-list__item">
                <span class="asm-list__type">{{ s.scope_type }}</span>
                <span class="asm-list__name">{{ labelFor(s) }}</span>
                <button
                    type="button"
                    class="asm-remove-btn"
                    @click="removeScope(s)"
                >
                    Release
                </button>
            </li>
            <li v-if="!myScopes.length" class="asm-hint">
                No exclusive claims — this admin hears the shared alert pool
                like everyone else.
            </li>
        </ul>
    </div>
</template>

<style scoped>
.asm-panel {
    font-family: 'DM Sans', system-ui, sans-serif;
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 14px;
    padding: 18px 20px;
    max-width: 460px;
}
.asm-title {
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
    margin: 0 0 4px;
}
.asm-hint {
    font-size: 11px;
    color: #94a3b8;
    margin: 0 0 12px;
}
.asm-add-row {
    display: flex;
    gap: 6px;
    margin-bottom: 12px;
}
.asm-select {
    font-family: inherit;
    font-size: 12px;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 6px 8px;
}
.asm-select--wide {
    flex: 1;
}
.asm-add-btn {
    padding: 6px 14px;
    background: #ea580c;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}
.asm-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.asm-list__item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    padding: 6px 0;
    border-bottom: 1px solid #f1f5f9;
}
.asm-list__type {
    text-transform: uppercase;
    font-size: 10px;
    font-weight: 700;
    color: #ea580c;
    background: #fff7ed;
    padding: 2px 6px;
    border-radius: 4px;
}
.asm-list__name {
    flex: 1;
    color: #1a2332;
    font-weight: 600;
}
.asm-remove-btn {
    background: none;
    border: none;
    color: #dc2626;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}
</style>
