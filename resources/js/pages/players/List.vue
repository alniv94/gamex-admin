<script setup lang="ts">
import { useTableFilters } from '@/composables/useTableFilters';
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { exportTableItems } from '@/composables/useTableExport';
import AdminDataTable from '@/components/AdminDataTable.vue';
import {
    index,
    show,
    exportMethod as exportPlayers,
} from '@/actions/App/Http/Controllers/PlayerController';
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import PlayerShell from '../../components/players/PlayerShell.vue';
import PlayerStatus from '../../components/players/PlayerStatus.vue';
import OtherDetails from '../../components/players/OtherDetails.vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import type { PageResult } from '../../types/ending';
import type { PlayerRecord } from '../../types/players';
import { playerDate } from '../../types/players';
const props = defineProps<{ players: PageResult<PlayerRecord>; filters: Record<string, string> }>();
const page = usePage();
const form = reactive({
    search: '',
    status: 'Active',
    account_verified: 'Files Submitted',
    type: 'Players',
    date_from: '',
    date_to: '',
    sort: 'id',
    direction: 'desc',
});
watch(
    () => props.filters,
    (filters) =>
        Object.assign(form, {
            search: '',
            status: 'Active',
            account_verified: 'Files Submitted',
            type: 'Players',
            date_from: '',
            date_to: '',
            sort: 'id',
            direction: 'desc',
            ...filters,
        }),
    { immediate: true },
);
const draft = reactive({ status: '', account_verified: '', type: '', date_from: '', date_to: '' });
const filterOpen = ref(false);
const loading = ref(false);
const filterError = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
function visit() {
    clearTimeout(timer);
    router.get(
        index.url(),
        { ...form, per_page: props.players.per_page },
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
    timer = setTimeout(visit, 350);
}
function openFilters() {
    Object.assign(draft, form);
    filterError.value = '';
    filterOpen.value = true;
}
function applyFilters() {
    if (draft.date_from && draft.date_to && draft.date_to < draft.date_from) {
        filterError.value = 'Date To must be on or after Date From.';
        return;
    }
    Object.assign(form, draft);
    visit();
}
function sort(key: string) {
    form.direction = form.sort === key && form.direction === 'asc' ? 'desc' : 'asc';
    form.sort = key;
    visit();
}
const headers = [
    { title: 'ID', key: 'id' },
    { title: 'Action', key: '' },
    { title: 'Name', key: 'name' },
    { title: 'Mobile Number', key: '' },
    { title: 'Email Address', key: '' },
    { title: 'Verification Status', key: 'account_verified' },
    { title: 'Verified Date', key: 'verified_at' },
    { title: 'Registered Date', key: 'created_at' },
    { title: 'Others', key: '' },
];
onBeforeUnmount(() => clearTimeout(timer));
const tableHeaders = [
    { title: 'ID', key: 'id' },
    { title: 'Action', key: 'actions', sortable: false },
    { title: 'Name', key: 'name' },
    { title: 'Mobile number', key: 'mobile_number' },
    { title: 'Email address', key: 'email_address' },
    { title: 'Verification status', key: 'account_verified' },
    { title: 'Verified date', key: 'verified_at' },
    { title: 'Registered date', key: 'created_at' },
];

const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => [
        'search',
        { key: 'status', defaultValue: 'Active' },
        { key: 'account_verified', defaultValue: 'Files Submitted' },
        { key: 'type', resetValue: 'Players', ignore: ['Players'] },
        'date_range',
        'duplicate_of',
    ],
    (changes) => {
        Object.assign(form, changes);
        visit();
    },
    undefined,
);
</script>

<template>
    <PlayerShell :title="filters.duplicate_of ? 'Duplicate Players' : 'Player List'">
        <v-card>
            <AdminTableToolbar
                :filters-open="filterOpen"
                :active-filters="activeFilters"
                @remove-filter="removeFilter"
                @reset-filters="resetTableFilters"
                :total="players.total"
                v-model="form.search"
                search-label="Search name, mobile, email"
                refresh-label="Refresh players"
                :exports="[
                    {
                        title: 'All matching players (CSV)',
                        href: exportPlayers.url({ query: { ...form } }),
                    },
                    {
                        title: 'Current page (CSV)',
                        action: () =>
                            exportTableItems('players-list.csv', players.data, tableHeaders),
                    },
                ]"
                @refresh="visit"
                @search="search"
                :loading="loading"
                filterable
                @filter="openFilters"
                filter-label="Filter players"
            >
            </AdminTableToolbar>
            <v-progress-linear v-if="loading" indeterminate color="primary" height="2" />
            <v-divider />
            <v-alert
                v-if="Object.keys(page.props.errors).length"
                type="error"
                variant="tonal"
                class="ma-4"
                >{{ Object.values(page.props.errors)[0] }}</v-alert
            >
            <AdminDataTable
                :headers="tableHeaders"
                :items="players.data"
                server
                :toolbar="false"
                title="Players"
            >
                <template #headers>
                    <tr>
                        <th
                            v-for="(header, n) in headers"
                            :key="n"
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
                <template #item="{ item: player }"
                    ><tr :key="player.id">
                        <td>{{ player.id }}</td>
                        <td>
                            <v-btn
                                :href="show.url(player.id)"
                                target="_blank"
                                rel="noopener"
                                icon="mdi-text-box-search-outline"
                                variant="text"
                                color="primary"
                                size="small"
                                :aria-label="`Details for ${player.name}`"
                                title="Details"
                            />
                        </td>
                        <td class="players-name">{{ player.name || '-' }}</td>
                        <td>{{ player.mobile_number || '-' }}</td>
                        <td>{{ player.email_address || '' }}</td>
                        <td><PlayerStatus :status="player.account_verified" /></td>
                        <td>{{ playerDate(player.verified_at) }}</td>
                        <td>{{ playerDate(player.created_at) }}</td>
                        <td><OtherDetails :player="player" /></td></tr
                ></template>
                <template #no-data> No data available </template>
            </AdminDataTable>
            <EndingPager :page="players" selectable />
        </v-card>
        <AdminFilterDialog
            v-model="filterOpen"
            :loading="loading"
            @apply="applyFilters"
            @reset="resetTableFilters"
        >
            <v-select
                v-model="draft.type"
                label="Type"
                :items="['Players', 'Duplicate Players']"
                variant="outlined"
                density="compact"
                class="mb-4"
            />
            <v-select
                v-model="draft.status"
                label="Status"
                :items="['All', 'Active', 'Inactive']"
                variant="outlined"
                density="compact"
                class="mb-4"
            />
            <v-select
                v-model="draft.account_verified"
                label="Verification Status"
                :items="['All', 'Verified', 'Pending', 'Files Submitted', 'Rejected']"
                variant="outlined"
                density="compact"
                class="mb-4"
            />
            <v-text-field v-model="draft.date_from" type="date" label="Date From" class="mb-4" />
            <v-text-field
                v-model="draft.date_to"
                type="date"
                label="Date To"
                :min="draft.date_from || undefined"
                class="mb-4"
                :error-messages="filterError"
            />
        </AdminFilterDialog>
    </PlayerShell>
</template>
