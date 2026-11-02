<script setup lang="ts">
import { useTableFilters } from '@/composables/useTableFilters';
import AdminDataTable from '@/components/AdminDataTable.vue';
import type { DataTableHeader } from 'vuetify';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EndingCatalogController from '@/actions/App/Http/Controllers/EndingCatalogController';
import EndingShell from '../../components/ending/EndingShell.vue';
import EndingToolbar from '../../components/ending/EndingToolbar.vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import EntityLogo from '../../components/ending/EntityLogo.vue';
import CatalogForm from '../../components/ending/CatalogForm.vue';
import { useEndingSearch, exportEndingRows } from '../../composables/useEndingTable';
import { displayDate } from '../../types/ending';
import type { League, PageResult } from '../../types/ending';
const props = defineProps<{
    records: PageResult<League>;
    filters: { search?: string };
    migrationReady: boolean;
}>();
const { search, filter } = useEndingSearch(
    EndingCatalogController.leagues.url(),
    props.filters.search,
    () => props.records.per_page,
);
const dialog = ref(false);
const selected = ref<League | null>(null);
function edit(league: League | null) {
    selected.value = league;
    dialog.value = true;
}
function exportRows() {
    exportEndingRows('ending-leagues.csv', [
        ['League', 'Logo', 'Locality', 'Status', 'Created at'],
        ...props.records.data.map((league) => [
            league.league,
            league.logo,
            league.is_local,
            league.is_active,
            displayDate(league.created_at || null),
        ]),
    ]);
}

const tableHeaders: DataTableHeader[] = [
    { title: 'Action', key: 'actions', sortable: false },
    { title: 'League', key: 'league', sortable: true },
    { title: 'Logo', key: 'logo', sortable: false },
    { title: 'Is local', key: 'is_local', sortable: true },
    { title: 'Status', key: 'is_active', sortable: true },
    { title: 'Created at', key: 'created_at', sortable: true },
];
const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    ['search'],
    (changes) => {
        search.value = String(changes.search ?? '');
        filter();
    },
);
</script>

<template>
    <EndingShell title="Leagues" :migration-ready="migrationReady">
        <v-card>
            <EndingToolbar
                :active-filters="activeFilters"
                @remove-filter="removeFilter"
                @reset-filters="resetTableFilters"
                :total="records.total"
                v-model="search"
                create-label="Create League"
                :disabled="!migrationReady"
                exportable
                @create="edit(null)"
                @refresh="router.reload()"
                @search="filter"
                @export="exportRows"
            />
            <v-divider />
            <AdminDataTable
                :headers="tableHeaders"
                :items="records.data"
                server
                :toolbar="false"
                title="Leagues"
            >
                <template #headers>
                    <tr>
                        <th class="text-center">Action</th>
                        <th>League</th>
                        <th>Logo</th>
                        <th>Is local</th>
                        <th>Status</th>
                        <th>Created at <v-icon icon="mdi-arrow-down" size="12" /></th>
                    </tr>
                </template>
                <template #item="{ item: league }"
                    ><tr :key="league.id">
                        <td class="text-center">
                            <v-btn
                                icon="mdi-square-edit-outline"
                                color="primary"
                                size="x-small"
                                variant="text"
                                :aria-label="`Edit ${league.league}`"
                                :disabled="!migrationReady"
                                @click="edit(league)"
                            />
                        </td>
                        <td>{{ league.league }}</td>
                        <td><EntityLogo :src="league.logo" :name="league.league" /></td>
                        <td>{{ league.is_local }}</td>
                        <td>
                            <v-chip
                                :color="league.is_active === 'Active' ? 'success' : 'default'"
                                size="x-small"
                                >{{ league.is_active }}</v-chip
                            >
                        </td>
                        <td class="text-no-wrap">{{ displayDate(league.created_at || null) }}</td>
                    </tr></template
                >
                <template #no-data>No leagues found.</template>
            </AdminDataTable>
            <EndingPager :page="records" selectable />
        </v-card>
        <CatalogForm v-model="dialog" kind="league" :league="selected" />
    </EndingShell>
</template>
