<script setup lang="ts">
import AddressController from '@/actions/App/Http/Controllers/Settings/AddressController';
import { edit } from '@/routes/address';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';

import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Address settings',
        href: edit().url,
    },
];

interface AddressProp {
    address_line_1: string | null;
    complex_name: string | null;
    suburb: string | null;
    unit_number: string | null;
    access_code: string | null;
    latitude: number | null;
    longitude: number | null;
}

const props = defineProps<{ address: AddressProp }>();

// ─── controlled fields (need to be settable from address autocomplete) ────
const addressLine1 = ref(props.address.address_line_1 || '');
const suburb = ref(props.address.suburb || '');
const accessCode = ref(props.address.access_code || '');
const latitude = ref<number | null>(props.address.latitude ?? null);
const longitude = ref<number | null>(props.address.longitude ?? null);

// ─── address autocomplete ───────────────────────────────────────────────────
const addressSuggestions = ref<any[]>([]);
const showSuggestions = ref(false);
let debounceTimeout: any = null;
let sessionToken: any = null;

const handleAddressSearch = async (event: any) => {
    const query = event.target.value;
    addressLine1.value = query;
    clearTimeout(debounceTimeout);
    if (query.length < 3) {
        addressSuggestions.value = [];
        return;
    }
    debounceTimeout = setTimeout(async () => {
        try {
            await new Promise<void>((resolve) => {
                const check = () => {
                    if ((window as any).google?.maps) resolve();
                    else setTimeout(check, 100);
                };
                check();
            });
            const { AutocompleteSuggestion, AutocompleteSessionToken } = await (
                window as any
            ).google.maps.importLibrary('places');
            if (!sessionToken) sessionToken = new AutocompleteSessionToken();
            const { suggestions } =
                await AutocompleteSuggestion.fetchAutocompleteSuggestions({
                    input: query,
                    sessionToken,
                });
            addressSuggestions.value = suggestions.map((s: any) => ({
                place_id: s.placePrediction.placeId,
                display_name: s.placePrediction.text.toString(),
            }));
            showSuggestions.value = true;
        } catch (e) {
            console.error('Places error:', e);
        }
    }, 400);
};

const selectAddress = async (item: any) => {
    showSuggestions.value = false;
    addressSuggestions.value = [];
    try {
        const { Place } = await (window as any).google.maps.importLibrary(
            'places',
        );
        const place = new Place({ id: item.place_id });
        await place.fetchFields({
            fields: ['addressComponents', 'formattedAddress', 'location'],
        });
        addressLine1.value = place.formattedAddress || item.display_name;
        latitude.value = place.location?.lat() ?? null;
        longitude.value = place.location?.lng() ?? null;
        const get = (type: string) =>
            place.addressComponents?.find((c: any) => c.types.includes(type))
                ?.longText || '';
        suburb.value =
            get('sublocality_level_1') ||
            get('locality') ||
            get('sublocality') ||
            '';
        sessionToken = null;
    } catch (e) {
        console.error(e);
    }
};

const hideSuggestions = () => {
    setTimeout(() => (showSuggestions.value = false), 200);
};
</script>

<template>
    <AppLayout>
        <Head title="Address settings" />

        <SettingsLayout>
            <div class="settings-card">
                <div class="settings-block__header">
                    <div class="settings-block__eyebrow">Account</div>
                    <h2 class="settings-block__title">Address</h2>
                    <p class="settings-block__sub">
                        Update your estate's physical address
                    </p>
                </div>

                <Form
                    v-bind="AddressController.update.form()"
                    class="settings-form"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div class="field" style="position: relative">
                        <label for="address_line_1" class="field__label"
                            >Search address</label
                        >
                        <input
                            id="address_line_1"
                            class="field__input"
                            name="address_line_1"
                            type="text"
                            :value="addressLine1"
                            required
                            autocomplete="off"
                            placeholder="Start typing the street address..."
                            @input="handleAddressSearch"
                            @blur="hideSuggestions"
                        />
                        <ul
                            v-if="showSuggestions && addressSuggestions.length"
                            class="address-suggestions"
                        >
                            <li
                                v-for="item in addressSuggestions"
                                :key="item.place_id"
                                @click="selectAddress(item)"
                                class="address-suggestions__item"
                            >
                                {{ item.display_name }}
                            </li>
                        </ul>
                        <InputError
                            class="mt-2"
                            :message="errors.address_line_1"
                        />
                    </div>

                    <div class="field">
                        <label for="suburb" class="field__label">Suburb</label>
                        <input
                            id="suburb"
                            class="field__input"
                            name="suburb"
                            type="text"
                            :value="suburb"
                            required
                        />
                        <InputError class="mt-2" :message="errors.suburb" />
                    </div>

                    <!-- <div class="field">
                        <label for="access_code" class="field__label"
                            >Access code</label
                        >
                        <input
                            id="access_code"
                            class="field__input"
                            name="access_code"
                            type="text"
                            :value="accessCode"
                            placeholder="Gate or entry code"
                        />
                        <InputError
                            class="mt-2"
                            :message="errors.access_code"
                        />
                    </div> -->

                    <input type="hidden" name="latitude" :value="latitude" />
                    <input type="hidden" name="longitude" :value="longitude" />

                    <div class="settings-form__actions">
                        <button
                            type="submit"
                            class="btn-primary"
                            :disabled="processing"
                            data-test="update-address-button"
                        >
                            Save
                        </button>

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p v-show="recentlySuccessful" class="saved-text">
                                Saved.
                            </p>
                        </Transition>
                    </div>
                </Form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.settings-block__header {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.settings-block__eyebrow {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #ea580c;
}
.settings-block__title {
    font-size: 18px;
    font-weight: 700;
    color: #1a2332;
    margin: 2px 0 0;
    letter-spacing: -0.2px;
}
.settings-block__sub {
    font-size: 13px;
    color: #64748b;
    margin: 4px 0 0;
}

.settings-card {
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.06),
        0 1px 2px rgba(0, 0, 0, 0.04);
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 22px;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.settings-card + .settings-card {
    margin-top: 16px;
}

.settings-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field__label {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.3px;
}
.field__input {
    width: 100%;
    box-sizing: border-box;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 14px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
    transition:
        border-color 0.15s,
        background 0.15s;
}
.field__input:focus {
    border-color: #ea580c;
    background: #fff;
}

.address-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 50;
    margin-top: 4px;
    max-height: 220px;
    overflow-y: auto;
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 10px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    list-style: none;
    padding: 0;
}
.address-suggestions__item {
    padding: 10px 14px;
    font-size: 13px;
    color: #1a2332;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
}
.address-suggestions__item:last-child {
    border-bottom: none;
}
.address-suggestions__item:hover {
    background: #fff7ed;
}

.settings-form__actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #ea580c;
    color: #ffffff;
    border: none;
    border-radius: 12px;
    padding: 10px 22px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    box-shadow: 0 2px 8px rgba(234, 88, 12, 0.3);
    white-space: nowrap;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-primary:hover:not(:disabled) {
    background: #c2410c;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(234, 88, 12, 0.35);
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.saved-text {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    margin: 0;
}
</style>
