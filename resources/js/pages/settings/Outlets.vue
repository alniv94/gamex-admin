<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import type { DataTableHeader } from 'vuetify';
import { index } from '@/actions/App/Http/Controllers/SettingsOutletController';
import AdminDataTable from '@/components/AdminDataTable.vue';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import OutletForm from '@/components/settings/OutletForm.vue';
import { useTableFilters } from '@/composables/useTableFilters';
import { exportTableItems } from '@/composables/useTableExport';
import type { PageResult } from '@/types/ending';
import type { Outlet, OutletLocation, OutletSort } from '@/types/outlets';
import { playerDate } from '@/types/players';

const props = defineProps<{
    outlets: PageResult<Outlet>;
    cities: OutletLocation[];
    filters: { search?: string; status?: string; sort?: OutletSort; direction?: 'asc' | 'desc' };
}>();
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const sort = ref<OutletSort>(props.filters.sort ?? 'created_at');
const direction = ref<'asc' | 'desc'>(props.filters.direction ?? 'desc');
const loading = ref(false);
const dialog = ref(false);
const selected = ref<Outlet | null>(null);
const filterOpen = ref(false);
const draft = reactive({ status: '' });
const columns: { title: string; key: OutletSort }[] = [
    { title: 'Outlet', key: 'outlet_name' },
    { title: 'Address', key: 'address' },
    { title: 'Status', key: 'is_active' },
    { title: 'Created at', key: 'created_at' },
];
const headers: DataTableHeader<Outlet>[] = [
    { title: 'Actions', key: 'actions', sortable: false },
    ...columns,
];
const exportHeaders: DataTableHeader<Outlet>[] = [
    ...columns,
    { title: 'Barangay', key: 'barangay' },
    { title: 'City / Municipality', key: 'city' },
];

function filter() {
    router.get(
        index.url(),
        {
            search: search.value,
            status: status.value,
            sort: sort.value,
            direction: direction.value,
            per_page: props.outlets.per_page,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
            onSuccess: () => (filterOpen.value = false),
        },
    );
}
function changeSort(key: OutletSort) {
    direction.value = sort.value === key && direction.value === 'asc' ? 'desc' : 'asc';
    sort.value = key;
    filter();
}
function edit(outlet: Outlet | null) {
    selected.value = outlet;
    dialog.value = true;
}
function openFilters() {
    draft.status = status.value;
    filterOpen.value = true;
}
function applyFilters() {
    status.value = draft.status;
    filter();
}
const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => ['search', { key: 'status', resetValue: '' }],
    (changes) => {
        search.value = String(changes.search ?? '');
        status.value = String(changes.status ?? '');
        filter();
    },
);
</script>

<template>
    <div>
        <Head title="Outlets" />
        <div class="players-page">
            <div class="players-breadcrumb">Settings</div>
            <div class="d-flex align-center justify-space-between flex-wrap ga-4 mb-6">
                <div>
                    <h1 class="players-title mb-1">Outlets</h1>
                    <p class="text-body-2 text-medium-emphasis">
                        Manage outlet locations and availability.
                    </p>
                </div>
                <v-btn color="primary" prepend-icon="mdi-plus" @click="edit(null)"
                    >Create outlet</v-btn
                >
            </div>
            <v-card>
                <AdminTableToolbar
                    v-model="search"
                    :total="outlets.total"
                    :loading="loading"
                    search-label="Search outlets"
                    refresh-label="Refresh outlets"
                    filterable
                    :filters-open="filterOpen"
                    :active-filters="activeFilters"
                    :exports="[
                        {
                            title: 'Current page (CSV)',
                            action: () =>
                                exportTableItems('outlets.csv', outlets.data, exportHeaders),
                        },
                    ]"
                    @search="filter"
                    @refresh="router.reload()"
                    @filter="openFilters"
                    @remove-filter="removeFilter"
                    @reset-filters="resetTableFilters"
                />
                <AdminDataTable
                    :headers="headers"
                    :items="outlets.data"
                    server
                    :toolbar="false"
                    :loading="loading"
                    title="Outlets"
                >
                    <template #headers>
                        <tr>
                            <th scope="col">Actions</th>
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                scope="col"
                                :aria-sort="
                                    sort === column.key
                                        ? direction === 'asc'
                                            ? 'ascending'
                                            : 'descending'
                                        : 'none'
                                "
                            >
                                <button
                                    type="button"
                                    class="outlet-sort"
                                    :disabled="loading"
                                    @click="changeSort(column.key)"
                                >
                                    {{ column.title }}
                                    <v-icon
                                        :icon="
                                            sort === column.key && direction === 'desc'
                                                ? 'mdi-arrow-down'
                                                : 'mdi-arrow-up'
                                        "
                                        size="14"
                                        :class="{ 'text-disabled': sort !== column.key }"
                                    />
                                </button>
                            </th>
                        </tr>
                    </template>
                    <template #item="{ item: outlet }">
                        <tr :key="outlet.id">
                            <td>
                                <v-btn
                                    icon="mdi-square-edit-outline"
                                    variant="text"
                                    color="primary"
                                    size="small"
                                    :aria-label="'Edit ' + outlet.outlet_name"
                                    @click="edit(outlet)"
                                />
                            </td>
                            <td>{{ outlet.outlet_name || '-' }}</td>
                            <td>
                                <div>{{ outlet.address || '-' }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ [outlet.barangay, outlet.city].filter(Boolean).join(', ') }}
                                </div>
                            </td>
                            <td>
                                <v-chip
                                    :color="outlet.is_active === 'Active' ? 'success' : 'default'"
                                    size="small"
                                    >{{ outlet.is_active || '-' }}</v-chip
                                >
                            </td>
                            <td class="text-no-wrap">{{ playerDate(outlet.created_at) }}</td>
                        </tr>
                    </template>
                    <template #no-data>No outlets found.</template>
                </AdminDataTable>
                <EndingPager :page="outlets" selectable />
            </v-card>
        </div>
        <OutletForm v-model="dialog" :outlet="selected" :cities="cities" />
        <AdminFilterDialog v-model="filterOpen" @apply="applyFilters" @reset="resetTableFilters">
            <v-select
                v-model="draft.status"
                label="Outlet status"
                :items="[
                    { title: 'All statuses', value: '' },
                    { title: 'Active', value: 'Active' },
                    { title: 'Inactive', value: 'Inactive' },
                ]"
                variant="outlined"
                density="compact"
                hide-details
            />
        </AdminFilterDialog>
    </div>
</template>

<style scoped>
.outlet-sort {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font: inherit;
    text-transform: inherit;
    color: inherit;
    cursor: pointer;
}
</style>
