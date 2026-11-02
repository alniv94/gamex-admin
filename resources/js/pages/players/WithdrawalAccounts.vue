<script setup lang="ts">
import { useTableFilters } from '@/composables/useTableFilters';
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { exportTableItems } from '@/composables/useTableExport';
import AdminDataTable from '@/components/AdminDataTable.vue';
import { show, withdrawalAccounts } from '@/actions/App/Http/Controllers/PlayerController';
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import PlayerShell from '../../components/players/PlayerShell.vue';
import PlayerStatus from '../../components/players/PlayerStatus.vue';
import type { PageResult } from '../../types/ending';
import type { WithdrawalAccount } from '../../types/players';
import { playerDate } from '../../types/players';

const props = defineProps<{
    accounts: PageResult<WithdrawalAccount>;
    filters: Record<string, string | null>;
    firstAccountDate: string | null;
    today: string;
}>();
const page = usePage();
const form = reactive({
    search: '',
    date_from: '',
    date_to: '',
    sort: 'created_at',
    direction: 'desc',
});
watch(
    () => props.filters,
    (filters) => {
        form.search = filters.search ?? '';
        form.date_from = filters.date_from ?? '';
        form.date_to = filters.date_to ?? '';
        form.sort = filters.sort ?? 'created_at';
        form.direction = filters.direction ?? 'desc';
    },
    { immediate: true },
);
const draft = reactive({ date_from: '', date_to: '' });
const filterOpen = ref(false);
const filterError = ref('');
const loading = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

function visit(targetPage = 1) {
    clearTimeout(timer);
    router.get(
        withdrawalAccounts.url(),
        { ...form, per_page: props.accounts.per_page, page: targetPage },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
            onSuccess: () => (filterOpen.value = false),
        },
    );
}
function search() {
    clearTimeout(timer);
    timer = setTimeout(() => visit(), 350);
}
function openFilters() {
    draft.date_from = form.date_from;
    draft.date_to = form.date_to || props.today;
    filterError.value = '';
    filterOpen.value = true;
}
function applyFilters() {
    if (!draft.date_from || !draft.date_to) {
        filterError.value = 'Date From and Date To are required.';
        return;
    }
    if (draft.date_to < draft.date_from) {
        filterError.value = 'Date To must be on or after Date From.';
        return;
    }
    Object.assign(form, draft);
    visit();
}
function resetFilters() {
    form.search = '';
    form.date_from = '';
    form.date_to = '';
    visit();
}
function sort(key: string) {
    form.direction = form.sort === key && form.direction === 'asc' ? 'desc' : 'asc';
    form.sort = key;
    visit();
}
function details(account: WithdrawalAccount) {
    if (account.player_id) router.visit(show(account.player_id));
}
const headers = [
    { title: 'Action', key: '' },
    { title: 'Outlet', key: 'bank' },
    { title: 'Account Number', key: 'account_number' },
    { title: 'Account Name', key: 'account_name' },
    { title: 'Status', key: 'is_verified' },
    { title: 'Date Created', key: 'created_at' },
];
onBeforeUnmount(() => clearTimeout(timer));

const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => ['search', 'date_range'],
    (changes) => {
        Object.assign(form, changes);
        visit();
    },
    resetFilters,
);
</script>

<template>
    <PlayerShell title="Player Withdrawal Account">
        <v-card>
            <AdminTableToolbar
                :filters-open="filterOpen"
                :active-filters="activeFilters"
                @remove-filter="removeFilter"
                @reset-filters="resetTableFilters"
                :total="accounts.total"
                v-model="form.search"
                search-label="Search withdrawal accounts"
                refresh-label="Refresh withdrawal accounts"
                :exports="[
                    {
                        title: 'Current page (CSV)',
                        action: () =>
                            exportTableItems(
                                'players-withdrawalaccounts.csv',
                                accounts.data,
                                headers,
                            ),
                    },
                ]"
                @refresh="visit(accounts.current_page)"
                @search="search"
                :loading="loading"
                filterable
                @filter="openFilters"
                filter-label="Filter withdrawal accounts"
            >
            </AdminTableToolbar>
            <v-progress-linear v-if="loading" indeterminate color="primary" height="2" />
            <v-divider />
            <v-alert
                v-if="Object.keys(page.props.errors).length"
                type="error"
                variant="tonal"
                class="ma-4"
                role="alert"
                >{{ Object.values(page.props.errors)[0] }}</v-alert
            >
            <AdminDataTable
                :aria-busy="loading"
                :headers="headers"
                :items="accounts.data"
                server
                :toolbar="false"
                title="Withdrawal accounts"
            >
                <template #headers>
                    <tr>
                        <th
                            v-for="header in headers"
                            :key="header.title"
                            :aria-sort="
                                header.key && form.sort === header.key
                                    ? form.direction === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : undefined
                            "
                        >
                            <button
                                v-if="header.key"
                                class="players-sort"
                                @click="sort(header.key)"
                            >
                                {{ header.title }}
                                <v-icon
                                    :icon="
                                        form.sort === header.key && form.direction === 'desc'
                                            ? 'mdi-arrow-down'
                                            : 'mdi-arrow-up'
                                    "
                                    size="14"
                                    :class="form.sort !== header.key ? 'players-sort-inactive' : ''"
                                />
                            </button>
                            <template v-else>{{ header.title }}</template>
                        </th>
                    </tr>
                </template>
                <template #item="{ item: account }"
                    ><tr :key="account.id">
                        <td>
                            <v-btn
                                :href="account.player_id ? show.url(account.player_id) : undefined"
                                :disabled="!account.player_id"
                                icon="mdi-text-box-search-outline"
                                variant="text"
                                color="primary"
                                size="small"
                                :aria-label="`Details for withdrawal account ${account.account_number}`"
                                :title="account.player_id ? 'Details' : 'Player unavailable'"
                                @click.prevent="details(account)"
                            />
                        </td>
                        <td>{{ account.bank || '-' }}</td>
                        <td>{{ account.account_number }}</td>
                        <td>{{ account.account_name || '-' }}</td>
                        <td><PlayerStatus :status="account.is_verified" /></td>
                        <td>{{ playerDate(account.created_at) }}</td>
                    </tr></template
                >
                <template #no-data> No data available </template>
            </AdminDataTable>
            <EndingPager :page="accounts" :page-sizes="[5, 10, 15, 20, 25]" selectable />
        </v-card>
        <AdminFilterDialog
            v-model="filterOpen"
            :loading="loading"
            @apply="applyFilters"
            @reset="resetTableFilters"
        >
            <v-text-field
                v-model="draft.date_from"
                type="date"
                label="Date From"
                prepend-icon="mdi-calendar"
                :min="firstAccountDate || undefined"
                :max="draft.date_to || today"
                required
                class="mb-4"
            />
            <v-text-field
                v-model="draft.date_to"
                type="date"
                label="Date To"
                prepend-icon="mdi-calendar"
                :min="draft.date_from || firstAccountDate || undefined"
                :max="today"
                required
                class="mb-4"
                :error-messages="filterError"
            />
        </AdminFilterDialog>
    </PlayerShell>
</template>
