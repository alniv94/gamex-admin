<script setup lang="ts">
import AdminDataTable from '@/components/AdminDataTable.vue';
import type { DataTableHeader } from 'vuetify';
import { subAccounts, subAccount } from '@/actions/App/Http/Controllers/InvestorController';
import type { Investor, InvestorSubAccount } from '@/types/investors';
import { investorName } from '@/types/investors';
import { playerDate } from '@/types/players';
import { useForm, useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{ investor: Investor }>();
const emit = defineEmits<{ close: [] }>();
const http = useHttp<Record<string, never>, { records: InvestorSubAccount[] }>({});
const loadError = ref('');
const editorOpen = ref(false);
const editing = ref<number>();
const form = useForm({
    first_name: '',
    last_name: '',
    mobile_number: '',
    password: '',
    status: 'Active',
});

async function load() {
    loadError.value = '';
    try {
        await http.get(subAccounts.url(props.investor.id));
    } catch {
        loadError.value = 'Sub-accounts could not be loaded. Please try again.';
    }
}
function edit(account?: InvestorSubAccount) {
    form.reset();
    form.clearErrors();
    editing.value = account?.id;
    if (account)
        Object.assign(form, {
            first_name: account.first_name,
            last_name: account.last_name,
            mobile_number: account.mobile_number,
            status: account.status,
        });
    editorOpen.value = true;
}
function save() {
    form.post(subAccount.url({ investor: props.investor.id, account: editing.value }), {
        preserveScroll: true,
        onSuccess: () => {
            editorOpen.value = false;
            form.reset('password');
            void load();
        },
    });
}
onMounted(load);
onBeforeUnmount(() => http.cancel());

const tableHeaders: DataTableHeader<InvestorSubAccount>[] = [
    { title: 'Action', key: 'actions', sortable: false },
    { title: 'Name', key: 'first_name', value: investorName, sortable: true },
    { title: 'Mobile number', key: 'mobile_number', sortable: true },
    { title: 'Status', key: 'status', sortable: true },
    { title: 'Date created', key: 'created_at', sortable: true },
];
</script>

<template>
    <v-card>
        <v-card-title class="d-flex align-center ga-3">
            <span>Sub-accounts — {{ investorName(investor) }}</span>
            <v-spacer />
            <v-btn
                icon="mdi-close"
                variant="text"
                aria-label="Close sub-accounts"
                @click="emit('close')"
            />
        </v-card-title>
        <v-card-text>
            <v-alert v-if="loadError" type="error" variant="tonal" class="mb-4" role="alert"
                >{{ loadError }}<v-btn variant="text" @click="load">Retry</v-btn></v-alert
            >
            <div class="d-flex align-center mb-3">
                <v-btn prepend-icon="mdi-plus" color="primary" @click="edit()"
                    >Add Sub-account</v-btn
                >
                <v-spacer />
                <v-btn
                    icon="mdi-refresh"
                    variant="text"
                    aria-label="Refresh sub-accounts"
                    :loading="http.processing"
                    @click="load"
                />
            </div>
            <v-progress-linear v-if="http.processing" indeterminate />
            <AdminDataTable
                :refresh="load"
                :aria-busy="http.processing"
                :headers="tableHeaders"
                :items="http.response?.records ?? []"
                :toolbar="true"
                title="Sub accounts"
            >
                <template #item="{ item: account }"
                    ><tr :key="account.id">
                        <td>
                            <v-btn
                                icon="mdi-square-edit-outline"
                                variant="text"
                                color="primary"
                                aria-label="Edit sub-account"
                                @click="edit(account)"
                            />
                        </td>
                        <td>{{ investorName(account) }}</td>
                        <td>+63{{ account.mobile_number }}</td>
                        <td>{{ account.status }}</td>
                        <td>{{ playerDate(account.created_at) }}</td>
                    </tr></template
                >
                <template #no-data>No sub-accounts.</template>
            </AdminDataTable>
        </v-card-text>
        <v-dialog v-model="editorOpen" max-width="600" persistent>
            <v-form @submit.prevent="save">
                <v-card :title="editing ? 'Edit Sub-account' : 'New Sub-account'">
                    <v-card-text>
                        <v-alert
                            v-if="form.hasErrors"
                            type="error"
                            variant="tonal"
                            class="mb-4"
                            role="alert"
                            >{{ Object.values(form.errors)[0] }}</v-alert
                        >
                        <v-text-field
                            v-model="form.first_name"
                            label="First name"
                            :error-messages="form.errors.first_name"
                        />
                        <v-text-field
                            v-model="form.last_name"
                            label="Last name"
                            :error-messages="form.errors.last_name"
                        />
                        <v-text-field
                            v-model="form.mobile_number"
                            label="Mobile number"
                            prefix="+63"
                            maxlength="10"
                            :error-messages="form.errors.mobile_number"
                        />
                        <v-text-field
                            v-model="form.password"
                            :label="editing ? 'New password (optional)' : 'Password'"
                            type="password"
                            autocomplete="new-password"
                            :error-messages="form.errors.password"
                        />
                        <v-select
                            v-model="form.status"
                            label="Status"
                            :items="['Active', 'Inactive']"
                            :error-messages="form.errors.status"
                        />
                    </v-card-text>
                    <v-card-actions
                        ><v-spacer /><v-btn :disabled="form.processing" @click="editorOpen = false"
                            >Cancel</v-btn
                        ><v-btn
                            type="submit"
                            color="primary"
                            variant="flat"
                            :loading="form.processing"
                            >Save Sub-account</v-btn
                        ></v-card-actions
                    >
                </v-card>
            </v-form>
        </v-dialog>
    </v-card>
</template>
