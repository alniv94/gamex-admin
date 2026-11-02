<script setup lang="ts">
import { store, update } from '@/actions/App/Http/Controllers/AgentController';
import type { AgentRow } from '@/types/agents';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ account?: AgentRow; outlets: { id: number; outlet_name: string }[] }>();
const open = defineModel<boolean>({ required: true });
const form = useForm({
    outlet_id: null as number | null,
    first_name: '',
    middle_name: '',
    last_name: '',
    mobile_number: '',
    email_address: '',
    username: '',
    password: '',
    password_confirmation: '',
    commission: '0.00',
    is_active: 'Active',
    type: 'Regular',
    sub_type: 'Streamer',
    page_name: '',
});
const showPassword = ref(false);
watch(open, (value) => {
    if (!value) return;
    form.reset();
    form.clearErrors();
    showPassword.value = false;
    if (props.account) {
        const account = props.account;
        Object.assign(form, {
            outlet_id: account.outlet_id,
            first_name: account.first_name ?? '',
            middle_name: account.middle_name ?? '',
            last_name: account.last_name ?? '',
            mobile_number: account.mobile_number ?? '',
            email_address: account.email_address ?? '',
            username: account.username,
            commission: account.commission,
            is_active: account.is_active,
            type: account.type,
            sub_type: account.sub_type ?? '',
            page_name: account.page_name ?? '',
        });
    } else if (props.outlets.length === 1) form.outlet_id = props.outlets[0]!.id;
});
function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset('password', 'password_confirmation');
        },
    };
    if (props.account) form.put(update.url(props.account.id), options);
    else form.post(store.url(), options);
}
const formError = computed(() => (form.errors as Record<string, string>).action);
const fieldDefaults = computed(() => ({
    VTextField: {
        variant: 'outlined',
        density: 'compact',
        hideDetails: 'auto',
        disabled: form.processing,
    },
    VSelect: {
        variant: 'outlined',
        density: 'compact',
        hideDetails: 'auto',
        disabled: form.processing,
    },
}));
</script>

<template>
    <v-dialog v-model="open" max-width="780" :persistent="form.processing" scrollable>
        <v-card class="agent-form-dialog">
            <div class="agent-form-header">
                <div class="agent-form-icon">
                    <v-icon>{{
                        account ? 'mdi-account-edit-outline' : 'mdi-account-plus-outline'
                    }}</v-icon>
                </div>
                <div>
                    <h2>{{ account ? 'Edit agent' : 'Create agent' }}</h2>
                    <p>
                        {{
                            account
                                ? 'Update profile, access, and commission settings.'
                                : 'Set up an agent account and commission rate.'
                        }}
                    </p>
                </div>
                <v-spacer /><v-btn
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    aria-label="Close agent form"
                    :disabled="form.processing"
                    @click="open = false"
                />
            </div>
            <v-card-text class="agent-form-body">
                <v-alert v-if="formError" type="error" variant="tonal" class="mb-5">{{
                    formError
                }}</v-alert>
                <v-defaults-provider :defaults="fieldDefaults">
                    <form id="agent-form" @submit.prevent="submit">
                        <fieldset class="agent-form-section">
                            <legend>
                                <v-icon size="18">mdi-account-outline</v-icon> Personal details
                            </legend>
                            <div class="agent-form-grid agent-form-names">
                                <v-text-field
                                    v-model="form.first_name"
                                    label="First name"
                                    autocomplete="given-name"
                                    maxlength="30"
                                    :error-messages="form.errors.first_name"
                                    required
                                    autofocus
                                />
                                <v-text-field
                                    v-model="form.middle_name"
                                    label="Middle name (optional)"
                                    autocomplete="additional-name"
                                    maxlength="30"
                                    :error-messages="form.errors.middle_name"
                                />
                                <v-text-field
                                    v-model="form.last_name"
                                    label="Last name"
                                    autocomplete="family-name"
                                    maxlength="30"
                                    :error-messages="form.errors.last_name"
                                    required
                                />
                            </div>
                            <div class="agent-form-grid">
                                <v-text-field
                                    v-model="form.mobile_number"
                                    label="Mobile number"
                                    prefix="+63"
                                    placeholder="9XXXXXXXXX"
                                    inputmode="tel"
                                    autocomplete="tel-national"
                                    maxlength="10"
                                    :error-messages="form.errors.mobile_number"
                                    required
                                />
                                <v-text-field
                                    v-model="form.email_address"
                                    label="Email address"
                                    type="email"
                                    autocomplete="email"
                                    maxlength="150"
                                    :error-messages="form.errors.email_address"
                                    required
                                />
                            </div>
                        </fieldset>
                        <fieldset class="agent-form-section">
                            <legend>
                                <v-icon size="18">mdi-shield-account-outline</v-icon> Account access
                            </legend>
                            <div class="agent-form-grid">
                                <v-text-field
                                    v-model="form.username"
                                    label="Username"
                                    autocomplete="off"
                                    hint="Letters, numbers, dots, hyphens, and underscores."
                                    :error-messages="form.errors.username"
                                    required
                                />
                                <v-select
                                    v-model="form.is_active"
                                    :items="['Active', 'Inactive']"
                                    label="Account status"
                                    :error-messages="form.errors.is_active"
                                />
                            </div>
                            <div v-if="!account" class="agent-form-grid">
                                <v-text-field
                                    v-model="form.password"
                                    label="Password"
                                    :type="showPassword ? 'text' : 'password'"
                                    :append-inner-icon="
                                        showPassword ? 'mdi-eye-off-outline' : 'mdi-eye-outline'
                                    "
                                    autocomplete="new-password"
                                    hint="Use at least 8 characters."
                                    :error-messages="form.errors.password"
                                    required
                                    @click:append-inner="showPassword = !showPassword"
                                />
                                <v-text-field
                                    v-model="form.password_confirmation"
                                    label="Confirm password"
                                    :type="showPassword ? 'text' : 'password'"
                                    autocomplete="new-password"
                                    :error-messages="form.errors.password_confirmation"
                                    required
                                />
                            </div>
                            <p v-else class="agent-form-help">
                                <v-icon size="15">mdi-lock-outline</v-icon> Change the password
                                using Reset password on the agent’s account page.
                            </p>
                        </fieldset>
                        <fieldset class="agent-form-section">
                            <legend>
                                <v-icon size="18">mdi-tune-variant</v-icon> Agent settings
                            </legend>
                            <div class="agent-form-grid">
                                <v-select
                                    v-model="form.outlet_id"
                                    :items="outlets"
                                    item-title="outlet_name"
                                    item-value="id"
                                    label="Outlet"
                                    :error-messages="form.errors.outlet_id"
                                />
                                <v-text-field
                                    v-model="form.commission"
                                    label="Commission rate"
                                    suffix="%"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    inputmode="decimal"
                                    :error-messages="form.errors.commission"
                                    required
                                />
                                <v-select
                                    v-model="form.type"
                                    :items="['Regular', 'Special']"
                                    label="Agent type"
                                    :error-messages="form.errors.type"
                                />
                                <v-select
                                    v-model="form.sub_type"
                                    :items="['Streamer', 'Special', 'Employee Agent']"
                                    label="Sub-type"
                                    :error-messages="form.errors.sub_type"
                                />
                                <v-text-field
                                    v-model="form.page_name"
                                    label="Page name (optional)"
                                    maxlength="100"
                                    class="agent-form-page-name"
                                    :error-messages="form.errors.page_name"
                                />
                            </div>
                        </fieldset>
                        <div v-if="!account" class="agent-form-wallet-note">
                            <v-icon size="20">mdi-wallet-outline</v-icon>
                            <p>
                                The wallet starts at <strong>PHP 0.00</strong>. Add funds from Cash
                                In after creating the account.
                            </p>
                        </div>
                    </form>
                </v-defaults-provider>
            </v-card-text>
            <v-card-actions class="agent-form-footer">
                <span class="agent-form-footer-hint">{{
                    account ? 'Changes apply after saving.' : 'A new agent account will be created.'
                }}</span>
                <v-spacer /><v-btn variant="text" :disabled="form.processing" @click="open = false"
                    >Cancel</v-btn
                ><v-btn
                    color="#007faa"
                    variant="flat"
                    type="submit"
                    form="agent-form"
                    height="42"
                    :loading="form.processing"
                    >{{ account ? 'Save changes' : 'Create agent' }}</v-btn
                >
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.agent-form-dialog {
    color: #243449;
    border-radius: 12px !important;
}
.agent-form-dialog :deep(.v-btn) {
    font-size: 13px;
    font-weight: 500;
    letter-spacing: 0;
    text-transform: none;
}
.agent-form-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 22px 24px;
    border-bottom: 1px solid #e8edf3;
}
.agent-form-icon {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 10px;
    color: #007faa;
    background: #edf8fc;
    flex-shrink: 0;
}
.agent-form-header h2 {
    font-size: 18px;
    line-height: 1.5;
}
.agent-form-header p {
    margin-top: 3px;
    font-size: 12px;
    color: #64748b;
}
.agent-form-body {
    padding: 24px !important;
}
.agent-form-section {
    border: 0;
    padding: 0;
    margin: 0 0 26px;
    min-width: 0;
}
.agent-form-section legend {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
    padding: 0;
    font-size: 13px;
    font-weight: 600;
    color: #34445a;
}
.agent-form-section legend .v-icon {
    color: #7b8fa5;
}
.agent-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 16px;
}
.agent-form-grid + .agent-form-grid {
    margin-top: 18px;
}
.agent-form-names {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.agent-form-grid .v-input {
    min-width: 0;
}
.agent-form-help {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: #64748b;
    margin: 12px 0 0;
    line-height: 1.7;
}
.agent-form-page-name {
    grid-column: 1 / -1;
}
.agent-form-wallet-note {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: #f3f9fd;
    border: 1px solid #e0edf5;
    border-radius: 8px;
    color: #64748b;
}
.agent-form-wallet-note > .v-icon {
    color: #007faa;
    flex-shrink: 0;
}
.agent-form-wallet-note p {
    font-size: 12px;
    line-height: 1.7;
}
.agent-form-wallet-note strong {
    font-weight: 500;
    color: #34445a;
}
.agent-form-footer {
    border-top: 1px solid #e8edf3;
    padding: 16px 24px !important;
    background: #fafbfd;
    gap: 8px;
}
.agent-form-footer-hint {
    font-size: 11px;
    color: #64748b;
}
@media (max-width: 600px) {
    .agent-form-header {
        padding: 18px 16px;
        gap: 10px;
    }
    .agent-form-icon {
        display: none;
    }
    .agent-form-header h2 {
        font-size: 17px;
    }
    .agent-form-body {
        padding: 22px 16px !important;
    }
    .agent-form-grid,
    .agent-form-names {
        grid-template-columns: minmax(0, 1fr);
        gap: 16px;
    }
    .agent-form-section {
        margin-bottom: 24px;
    }
    .agent-form-footer {
        padding: 14px 16px !important;
    }
    .agent-form-footer-hint {
        display: none;
    }
}
</style>
