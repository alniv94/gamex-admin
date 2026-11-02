<script setup lang="ts">
import { store, update } from '@/actions/App/Http/Controllers/InvestorController';
import type { Investor, Outlet } from '@/types/investors';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ account?: Investor; outlets: Outlet[] }>();
const emit = defineEmits<{ saved: []; cancel: [] }>();
const form = useForm({
    outlet_id: props.account?.outlet_id ?? props.outlets[0]?.id ?? null,
    territory: props.account?.territory ?? '',
    username: props.account?.username ?? '',
    first_name: props.account?.first_name ?? '',
    last_name: props.account?.last_name ?? '',
    mobile_number: props.account?.mobile_number ?? '',
    password: '',
    base_commission: props.account?.base_commission ?? '0',
    commission: props.account?.commission ?? '0',
    sub_commission: props.account?.sub_commission ?? '0',
    wallet: props.account?.wallet ?? '0',
    category: props.account?.category ?? 'Regular',
    link_type: props.account?.link_type ?? 'New',
    status: props.account?.status ?? 'Active',
});
function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('password');
            emit('saved');
        },
    };
    if (props.account) form.put(update.url(props.account.id), options);
    else form.post(store.url(), options);
}
</script>

<template>
    <v-form @submit.prevent="submit">
        <v-card :title="account ? 'Edit Investor' : 'New Investor'">
            <v-card-text>
                <v-alert
                    v-if="form.hasErrors"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                    role="alert"
                >
                    {{ Object.values(form.errors)[0] }}
                </v-alert>
                <v-row>
                    <v-col cols="12" md="6"
                        ><v-select
                            v-model="form.outlet_id"
                            label="Outlet"
                            :items="outlets"
                            item-title="outlet_name"
                            item-value="id"
                            :error-messages="form.errors.outlet_id"
                    /></v-col>
                    <v-col cols="12" md="6"
                        ><v-text-field
                            v-model="form.territory"
                            label="Territory"
                            hint="Letters, numbers, underscores and hyphens"
                            :error-messages="form.errors.territory"
                    /></v-col>
                    <v-col v-if="!account" cols="12"
                        ><v-text-field
                            v-model="form.username"
                            label="Username"
                            autocomplete="off"
                            :error-messages="form.errors.username"
                    /></v-col>
                    <v-col cols="12" md="6"
                        ><v-text-field
                            v-model="form.first_name"
                            label="First name"
                            :error-messages="form.errors.first_name"
                    /></v-col>
                    <v-col cols="12" md="6"
                        ><v-text-field
                            v-model="form.last_name"
                            label="Last name"
                            :error-messages="form.errors.last_name"
                    /></v-col>
                    <v-col cols="12" md="6"
                        ><v-text-field
                            v-model="form.mobile_number"
                            label="Mobile number"
                            prefix="+63"
                            maxlength="10"
                            :error-messages="form.errors.mobile_number"
                    /></v-col>
                    <v-col cols="12" md="6"
                        ><v-text-field
                            v-model="form.password"
                            :label="account ? 'New password (optional)' : 'Password'"
                            type="password"
                            autocomplete="new-password"
                            :error-messages="form.errors.password"
                    /></v-col>
                    <v-col cols="12" md="4"
                        ><v-text-field
                            v-model="form.base_commission"
                            label="Base commission (%)"
                            type="number"
                            min="0"
                            max="100"
                            step=".01"
                            :error-messages="form.errors.base_commission"
                    /></v-col>
                    <v-col cols="12" md="4"
                        ><v-text-field
                            v-model="form.commission"
                            label="Commission (%)"
                            type="number"
                            min="0"
                            max="100"
                            step=".01"
                            :error-messages="form.errors.commission"
                    /></v-col>
                    <v-col cols="12" md="4"
                        ><v-text-field
                            v-model="form.sub_commission"
                            label="Sub commission (%)"
                            type="number"
                            min="0"
                            max="100"
                            step=".01"
                            :error-messages="form.errors.sub_commission"
                    /></v-col>
                    <v-col v-if="!account" cols="12" md="6"
                        ><v-text-field
                            v-model="form.wallet"
                            label="Opening wallet"
                            type="number"
                            min="0"
                            step=".01"
                            :error-messages="form.errors.wallet"
                    /></v-col>
                    <v-col cols="12" md="6"
                        ><v-select
                            v-model="form.category"
                            label="Category"
                            :items="['Regular', 'Special']"
                            :error-messages="form.errors.category"
                    /></v-col>
                    <v-col cols="12" md="6"
                        ><v-select
                            v-model="form.link_type"
                            label="Referral link type"
                            :items="account?.link_type === 'New' ? ['New'] : ['Old', 'New']"
                            :error-messages="form.errors.link_type"
                    /></v-col>
                    <v-col cols="12" md="6"
                        ><v-select
                            v-model="form.status"
                            label="Status"
                            :items="['Active', 'Inactive']"
                            :error-messages="form.errors.status"
                    /></v-col>
                </v-row>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn :disabled="form.processing" @click="emit('cancel')">Cancel</v-btn>
                <v-btn color="primary" variant="flat" type="submit" :loading="form.processing"
                    >Save Investor</v-btn
                >
            </v-card-actions>
        </v-card>
    </v-form>
</template>
