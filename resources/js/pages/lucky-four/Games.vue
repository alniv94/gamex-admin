<script setup lang="ts">
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import { useTableFilters } from '@/composables/useTableFilters';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { exportTableItems } from '@/composables/useTableExport';
import AdminDataTable from '@/components/AdminDataTable.vue';
import type { DataTableHeader } from 'vuetify';
import { reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { index, show } from '@/actions/App/Http/Controllers/LuckyFourController';
import LuckyFourShell from '@/components/lucky-four/LuckyFourShell.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import EntityLogo from '@/components/ending/EntityLogo.vue';
import type { EndingGame, PageResult } from '@/types/ending';
import { displayDate } from '@/types/ending';
import type { LuckyFourFilters } from '@/types/lucky-four';
const props = defineProps<{
    games: PageResult<EndingGame>;
    filters: LuckyFourFilters;
    migrationReady: boolean;
}>();
const form = useForm({
    search: props.filters.search || '',
    status: props.filters.status || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
});
function filter() {
    form.transform((data) => ({ ...data, per_page: props.games.per_page })).get(index.url(), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => (filterOpen.value = false),
    });
}

const tableHeaders: DataTableHeader[] = [
    { title: 'Game', key: 'id', sortable: true },
    { title: 'Matchup', key: 'team_a_name', sortable: true },
    { title: 'League', key: 'league', sortable: true },
    { title: 'Schedule', key: 'schedule', sortable: true },
    { title: 'Status', key: 'status', sortable: true },
    { title: 'Betting', key: 'betting', sortable: true },
    { title: 'actions', key: 'actions', sortable: false },
];

const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => [
        'search',
        {
            key: 'status',
            resetValue: '',
            options: [
                { title: 'Scheduled', value: 'scheduled' },
                { title: 'Ongoing', value: 'ongoing' },
                { title: 'Finished', value: 'finished' },
            ],
        },
        'date_range',
    ],
    (changes) => {
        Object.assign(form, changes);
        filter();
    },
    undefined,
);
const filterOpen = ref(false);
const draft = reactive({ ...form.data() });
function openFilters() {
    Object.assign(draft, form.data());
    filterOpen.value = true;
}
function applyFilters() {
    Object.assign(form, draft);
    filter();
}
</script>
<template>
    <LuckyFourShell title="Games" :migration-ready="migrationReady">
        <p class="mb-4 text-medium-emphasis">
            Enable Lucky Four from an Ending game's details. The schedule and results are shared.
        </p>
        <v-card>
            <AdminTableToolbar
                :filters-open="filterOpen"
                filterable
                @filter="openFilters"
                :active-filters="activeFilters"
                @remove-filter="removeFilter"
                @reset-filters="resetTableFilters"
                :total="games.total"
                v-model="form.search"
                search-label="Search teams or game ID"
                :loading="form.processing"
                :exports="[
                    {
                        title: 'Current page (CSV)',
                        action: () =>
                            exportTableItems('lucky-four-games.csv', games.data, tableHeaders),
                    },
                ]"
                @search="filter"
                @refresh="filter"
            ></AdminTableToolbar>
            <AdminDataTable
                :headers="tableHeaders"
                :items="games.data"
                server
                :toolbar="false"
                title="Games"
            >
                <template #headers>
                    <tr>
                        <th>Game</th>
                        <th>Matchup</th>
                        <th>League</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Betting</th>
                        <th />
                    </tr>
                </template>
                <template #item="{ item: game }"
                    ><tr :key="game.id">
                        <td>#{{ game.id }}</td>
                        <td>
                            <div class="d-flex align-center ga-2 py-3">
                                <EntityLogo
                                    :src="game.team_a_logo"
                                    :name="game.team_a_name"
                                    :size="28"
                                />{{ game.team_a_name }} <span class="text-medium-emphasis">vs</span
                                ><EntityLogo
                                    :src="game.team_b_logo"
                                    :name="game.team_b_name"
                                    :size="28"
                                />{{ game.team_b_name }}
                            </div>
                        </td>
                        <td>{{ game.league }}</td>
                        <td>{{ displayDate(game.schedule) }}</td>
                        <td>{{ game.status }}</td>
                        <td>{{ game.betting }}</td>
                        <td>
                            <v-btn variant="text" size="small" @click="router.visit(show(game.id))"
                                >View game</v-btn
                            >
                        </td>
                    </tr></template
                >
                <template #no-data> No Lucky Four games match these filters. </template>
            </AdminDataTable>
            <EndingPager :page="games" selectable />
        </v-card>
        <AdminFilterDialog
            v-model="filterOpen"
            :loading="form.processing"
            @apply="applyFilters"
            @reset="resetTableFilters"
        >
            <v-row dense>
                <v-col cols="12" sm="6"
                    ><v-select
                        v-model="draft.status"
                        label="Status"
                        :items="[
                            { title: 'All', value: '' },
                            { title: 'Scheduled', value: 'scheduled' },
                            { title: 'Ongoing', value: 'ongoing' },
                            { title: 'Finished', value: 'finished' },
                        ]"
                        hide-details
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.date_from"
                        type="date"
                        label="From (Manila)"
                        :error-messages="form.errors.date_from"
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.date_to"
                        type="date"
                        label="To (Manila)"
                        :error-messages="form.errors.date_to"
                /></v-col>
            </v-row> </AdminFilterDialog
    ></LuckyFourShell>
</template>
