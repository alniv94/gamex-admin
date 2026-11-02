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
import type { Team, League, PageResult } from '../../types/ending';
const props = defineProps<{
    records: PageResult<Team>;
    leagues: League[];
    filters: { search?: string };
    migrationReady: boolean;
}>();
const { search, filter } = useEndingSearch(
    EndingCatalogController.teams.url(),
    props.filters.search,
    () => props.records.per_page,
);
const dialog = ref(false);
const selected = ref<Team | null>(null);
function edit(team: Team | null) {
    selected.value = team;
    dialog.value = true;
}
function exportRows() {
    exportEndingRows('ending-teams.csv', [
        ['League', 'Name', 'Alias', 'Logo', 'Status', 'Created at'],
        ...props.records.data.map((team) => [
            team.league?.league,
            team.team_name,
            team.team_alias,
            team.logo,
            team.is_active,
            displayDate(team.created_at || null),
        ]),
    ]);
}

const tableHeaders: DataTableHeader[] = [
    { title: 'Action', key: 'actions', sortable: false },
    { title: 'League', key: 'league.league', sortable: true },
    { title: 'Logo', key: 'logo', sortable: false },
    { title: 'Name', key: 'team_name', sortable: true },
    { title: 'Alias', key: 'team_alias', sortable: true },
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
    <EndingShell title="Teams" :migration-ready="migrationReady">
        <v-card>
            <EndingToolbar
                :active-filters="activeFilters"
                @remove-filter="removeFilter"
                @reset-filters="resetTableFilters"
                :total="records.total"
                v-model="search"
                create-label="Create Team"
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
                title="Teams"
            >
                <template #headers>
                    <tr>
                        <th class="text-center">Action</th>
                        <th>League</th>
                        <th>Logo</th>
                        <th>Name</th>
                        <th>Alias</th>
                        <th>Status</th>
                        <th>Created at <v-icon icon="mdi-arrow-down" size="12" /></th>
                    </tr>
                </template>
                <template #item="{ item: team }"
                    ><tr :key="team.id">
                        <td class="text-center">
                            <v-btn
                                icon="mdi-square-edit-outline"
                                color="primary"
                                size="x-small"
                                variant="text"
                                :aria-label="`Edit ${team.team_name}`"
                                :disabled="!migrationReady"
                                @click="edit(team)"
                            />
                        </td>
                        <td>{{ team.league?.league || '—' }}</td>
                        <td><EntityLogo :src="team.logo" :name="team.team_name" /></td>
                        <td>{{ team.team_name }}</td>
                        <td>{{ team.team_alias || '—' }}</td>
                        <td>
                            <v-chip
                                :color="team.is_active === 'Active' ? 'success' : 'default'"
                                size="x-small"
                                >{{ team.is_active }}</v-chip
                            >
                        </td>
                        <td class="text-no-wrap">{{ displayDate(team.created_at || null) }}</td>
                    </tr></template
                >
                <template #no-data>No teams found.</template>
            </AdminDataTable>
            <EndingPager :page="records" selectable />
        </v-card>
        <CatalogForm v-model="dialog" kind="team" :team="selected" :leagues="leagues" />
    </EndingShell>
</template>
