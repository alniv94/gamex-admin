<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { useForm, useHttp } from '@inertiajs/vue3';
import { barangays, store, update } from '@/actions/App/Http/Controllers/SettingsOutletController';
import type { Outlet, OutletLocation } from '@/types/outlets';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{ outlet: Outlet | null; cities: OutletLocation[] }>();
const form = useForm({
    outlet_name: '',
    city_id: null as number | null,
    barangay_id: null as number | null,
    address: '',
    is_active: 'Active',
    version: 0,
});
const http = useHttp<Record<string, never>, { records: OutletLocation[] }>({});
const locations = ref<OutletLocation[]>([]);
const loadError = ref('');
let requestId = 0;

watch(open, (value) => {
    if (!value) return;
    form.clearErrors();
    Object.assign(form, {
        outlet_name: props.outlet?.outlet_name ?? '',
        city_id: props.outlet?.city_id ?? null,
        barangay_id: props.outlet?.barangay_id ?? null,
        address: props.outlet?.address ?? '',
        is_active: props.outlet?.is_active ?? 'Active',
        version: props.outlet?.version ?? 0,
    });
});

async function loadBarangays() {
    const currentRequest = ++requestId;
    http.cancel();
    locations.value = [];
    loadError.value = '';
    if (!open.value || !form.city_id) return;
    try {
        await http.get(barangays.url({ query: { city_id: form.city_id } }));
        if (currentRequest === requestId) locations.value = http.response?.records ?? [];
    } catch {
        if (currentRequest === requestId && open.value)
            loadError.value = 'Barangays could not be loaded. Please try again.';
    }
}

watch([open, () => form.city_id], ([isOpen, city], [wasOpen, previousCity]) => {
    if (isOpen && wasOpen && city !== previousCity) form.barangay_id = null;
    void loadBarangays();
});
onBeforeUnmount(() => http.cancel());

function save() {
    if (form.processing || http.processing || loadError.value) return;
    form.submit(props.outlet ? update(props.outlet.id) : store(), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <v-dialog
        v-model="open"
        max-width="560"
        scrollable
        :persistent="form.processing"
        :aria-label="outlet ? 'Edit outlet' : 'Create outlet'"
    >
        <v-card>
            <v-card-title>{{ outlet ? 'Edit outlet' : 'Create outlet' }}</v-card-title>
            <v-divider />
            <v-card-text>
                <form id="outlet-form" @submit.prevent="save">
                    <v-alert
                        v-if="form.errors.version"
                        type="error"
                        variant="tonal"
                        class="mb-4"
                        role="alert"
                    >
                        {{ form.errors.version }}
                    </v-alert>
                    <v-text-field
                        v-model="form.outlet_name"
                        label="Outlet name"
                        variant="outlined"
                        :error-messages="form.errors.outlet_name"
                        :disabled="form.processing"
                        maxlength="255"
                        required
                    />
                    <p class="text-overline mb-2">Address</p>
                    <v-autocomplete
                        v-model="form.city_id"
                        :items="cities"
                        item-title="name"
                        item-value="id"
                        label="City / Municipality"
                        variant="outlined"
                        :error-messages="form.errors.city_id"
                        :disabled="form.processing"
                        clearable
                    />
                    <v-alert
                        v-if="loadError"
                        type="error"
                        variant="tonal"
                        class="mb-3"
                        role="alert"
                    >
                        {{ loadError }}
                        <v-btn variant="text" size="small" @click="loadBarangays">Retry</v-btn>
                    </v-alert>
                    <v-autocomplete
                        v-model="form.barangay_id"
                        :items="locations"
                        item-title="name"
                        item-value="id"
                        label="Barangay"
                        variant="outlined"
                        :loading="http.processing"
                        :disabled="
                            !form.city_id || http.processing || form.processing || !!loadError
                        "
                        :error-messages="form.errors.barangay_id"
                        clearable
                    />
                    <v-textarea
                        v-model="form.address"
                        label="Street, building, floor or unit"
                        variant="outlined"
                        :error-messages="form.errors.address"
                        :disabled="form.processing"
                        maxlength="200"
                        rows="2"
                        auto-grow
                        required
                    />
                    <v-switch
                        v-model="form.is_active"
                        true-value="Active"
                        false-value="Inactive"
                        :label="form.is_active"
                        :error-messages="form.errors.is_active"
                        :disabled="form.processing"
                        color="success"
                    />
                </form>
            </v-card-text>
            <v-divider />
            <v-card-actions>
                <v-spacer />
                <v-btn :disabled="form.processing" @click="open = false">Cancel</v-btn>
                <v-btn
                    type="submit"
                    form="outlet-form"
                    color="primary"
                    variant="flat"
                    :loading="form.processing"
                    :disabled="http.processing || !!loadError"
                    >{{ outlet ? 'Save changes' : 'Create outlet' }}</v-btn
                >
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
