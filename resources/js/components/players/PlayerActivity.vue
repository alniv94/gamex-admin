<script setup lang="ts">
import { useTableFilters } from '@/composables/useTableFilters';
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { exportTableItems } from '@/composables/useTableExport';
import AdminDataTable from '@/components/AdminDataTable.vue';
import type { DataTableHeader } from 'vuetify';
import { show } from '@/actions/App/Http/Controllers/PlayerController';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import EndingPager from '../ending/EndingPager.vue';
import type { PageResult } from '../../types/ending';
import type { PlayerActivity } from '../../types/players';
import { playerDate, playerMoney } from '../../types/players';
const props = defineProps<{
    playerId: number;
    activity: PageResult<PlayerActivity>;
    filters: Record<string, string>;
}>();
const tab = ref('transactions');
const search = ref('');
const loading = ref(false);
const filterOpen = ref(false);
const dates = reactive({ activity_date_from: '', activity_date_to: '' });
const draftDates = reactive({ ...dates });
const error = ref('');
watch(
    () => props.filters,
    (filters) => {
        tab.value = filters.tab || 'transactions';
        search.value = filters.activity_search || '';
        Object.assign(dates, {
            activity_date_from: filters.activity_date_from || '',
            activity_date_to: filters.activity_date_to || '',
        });
    },
    { immediate: true },
);
let timer: ReturnType<typeof setTimeout> | undefined;
function visit(nextTab = tab.value) {
    clearTimeout(timer);
    router.get(
        show.url(props.playerId),
        {
            tab: nextTab,
            activity_search: search.value,
            ...dates,
            per_page: props.activity.per_page,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['activity', 'filters'],
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
            onSuccess: () => (filterOpen.value = false),
        },
    );
}
function debounceSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => visit(), 350);
}
function applyDates() {
    if (
        draftDates.activity_date_from &&
        draftDates.activity_date_to &&
        draftDates.activity_date_to < draftDates.activity_date_from
    ) {
        error.value = 'Date To must be on or after Date From.';
        return;
    }
    error.value = '';
    Object.assign(dates, draftDates);
    visit();
}
function openFilters() {
    Object.assign(draftDates, dates);
    error.value = '';
    filterOpen.value = true;
}
onBeforeUnmount(() => clearTimeout(timer));

const tableHeaders: DataTableHeader[] = [
    { title: 'ID', key: 'id', sortable: true },
    { title: 'Admin', key: 'admin', sortable: true },
    { title: 'Transaction Type', key: 'transaction_type', sortable: true },
    { title: 'Details', key: 'details', sortable: true },
    { title: 'Amount', key: 'amount', sortable: true },
    { title: 'Date', key: 'created_at', sortable: true },
];

const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => ['activity_search', 'activity_dates'],
    (changes) => {
        search.value = String(changes.activity_search ?? '');
        Object.assign(dates, changes);
        visit();
    },
    undefined,
);
</script>
<template>
    <v-card>
        <v-tabs
            :model-value="tab"
            color="primary"
            density="compact"
            @update:model-value="(value) => visit(String(value))"
        >
            <v-tab value="transactions">Transactions</v-tab
            ><v-tab value="player_logs">Player Logs</v-tab
            ><v-tab value="admin_logs">Admin Logs</v-tab>
        </v-tabs>
        <v-divider />
        <AdminTableToolbar
            :filters-open="filterOpen"
            :active-filters="activeFilters"
            @remove-filter="removeFilter"
            @reset-filters="resetTableFilters"
            :total="activity.total"
            v-model="search"
            search-label="Search activity"
            refresh-label="Refresh activity"
            :exports="[
                {
                    title: 'Current page (CSV)',
                    action: () =>
                        exportTableItems('players-playeractivity.csv', activity.data, tableHeaders),
                },
            ]"
            @refresh="visit()"
            @search="debounceSearch"
            :loading="loading"
            filterable
            @filter="openFilters"
            filter-label="Filter activity"
        >
        </AdminTableToolbar>
        <v-progress-linear v-if="loading" indeterminate height="2" color="primary" />
        <v-divider />
        <AdminDataTable
            v-if="activity.data.length"
            :headers="tableHeaders"
            :items="activity.data"
            server
            :toolbar="false"
            title="Activity"
        >
            <template #headers>
                <tr>
                    <th>ID</th>
                    <th v-if="tab === 'admin_logs'">Admin</th>
                    <th v-if="tab === 'transactions'">Transaction Type</th>
                    <th>Details</th>
                    <th v-if="tab === 'transactions'">Amount</th>
                    <th>Date</th>
                </tr>
            </template>
            <template #item="{ item: row }"
                ><tr :key="`${row.origin || tab}-${row.id}`">
                    <td>{{ row.id }}</td>
                    <td v-if="tab === 'admin_logs'">{{ row.admin || '-' }}</td>
                    <td v-if="tab === 'transactions'">{{ row.transaction_type || '-' }}</td>
                    <td class="activity-description">{{ row.details || '-' }}</td>
                    <td v-if="tab === 'transactions'">{{ playerMoney(row.amount) }}</td>
                    <td>{{ playerDate(row.created_at) }}</td>
                </tr></template
            >
        </AdminDataTable>
        <div v-else class="text-center text-medium-emphasis pa-6">No data available</div>
        <EndingPager v-if="activity.total" :page="activity" selectable />
        <AdminFilterDialog
            v-model="filterOpen"
            :loading="loading"
            @apply="applyDates"
            @reset="resetTableFilters"
        >
            <v-text-field
                v-model="draftDates.activity_date_from"
                label="Date From"
                type="date"
                class="mb-4"
            /><v-text-field
                v-model="draftDates.activity_date_to"
                label="Date To"
                type="date"
                :min="draftDates.activity_date_from || undefined"
                :error-messages="error"
                class="mb-4"
            />
        </AdminFilterDialog>
    </v-card>
</template>
