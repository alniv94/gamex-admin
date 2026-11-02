<script setup lang="ts">
import { useTableFilters } from '@/composables/useTableFilters';
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import AdminDataTable from '@/components/AdminDataTable.vue';
import type { DataTableHeader } from 'vuetify';
import { usePermissions } from '@/composables/usePermissions';
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import { router, usePage, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useDate } from 'vuetify';
import EndingShell from '../../components/ending/EndingShell.vue';
import EndingToolbar from '../../components/ending/EndingToolbar.vue';
import EntityLogo from '../../components/ending/EntityLogo.vue';
import GameForm from '../../components/ending/GameForm.vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import { exportEndingRows } from '../../composables/useEndingTable';
import type { AdminPageProps } from '../../types';
import type { EndingGame, League, Team, Preset, PageResult } from '../../types/ending';
import { displayDate } from '../../types/ending';
const props = defineProps<{
    games: PageResult<EndingGame>;
    leagues: League[];
    teams: Team[];
    presets: Preset[];
    filters: Record<string, string>;
    migrationReady: boolean;
}>();
const page = usePage<AdminPageProps>();
const { can } = usePermissions();
const canCreate = computed(() => can('Ending Game Create'));
const form = reactive({
    search: props.filters.search || '',
    status: props.filters.status || '',
    league_id: Number(props.filters.league_id) || null,
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
    sort: props.filters.sort || 'schedule',
    direction: props.filters.direction || 'desc',
});
const create = ref(false);
const filtersOpen = ref(false);
const draft = reactive({
    status: form.status,
    league_id: form.league_id,
    date_from: form.date_from,
    date_to: form.date_to,
});
const dateAdapter = useDate();
const startDate = computed<Date | null>({
    get: () =>
        draft.date_from ? dateAdapter.toJsDate(dateAdapter.parseISO(draft.date_from)) : null,
    set: (value) => {
        draft.date_from = value ? dateAdapter.toISO(value) : '';
    },
});
const endDate = computed<Date | null>({
    get: () => (draft.date_to ? dateAdapter.toJsDate(dateAdapter.parseISO(draft.date_to)) : null),
    set: (value) => {
        draft.date_to = value ? dateAdapter.toISO(value) : '';
    },
});
watch(
    () => props.filters,
    (filters) => {
        Object.assign(form, {
            search: filters.search || '',
            status: filters.status || '',
            league_id: Number(filters.league_id) || null,
            date_from: filters.date_from || '',
            date_to: filters.date_to || '',
            sort: filters.sort || 'schedule',
            direction: filters.direction || 'desc',
        });
    },
);
let timer: ReturnType<typeof setTimeout> | undefined;
function visit(filters: Record<string, string | number | null>, closeFilters = false) {
    clearTimeout(timer);
    router.get(
        EndingGameController.index.url(),
        { ...filters, per_page: props.games.per_page },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => {
                if (closeFilters) filtersOpen.value = false;
            },
        },
    );
}
function filter() {
    visit(form);
}
function openFilters() {
    Object.assign(draft, {
        status: form.status,
        league_id: form.league_id,
        date_from: form.date_from,
        date_to: form.date_to,
    });
    filtersOpen.value = true;
}
function applyFilters() {
    visit({ ...form, ...draft, date_to: draft.date_to || draft.date_from }, true);
}
function refresh() {
    visit({ search: form.search, sort: form.sort, direction: form.direction }, true);
}
function search() {
    clearTimeout(timer);
    timer = setTimeout(filter, 300);
}
onBeforeUnmount(() => clearTimeout(timer));
function sort(column: string) {
    form.direction = form.sort === column && form.direction === 'desc' ? 'asc' : 'desc';
    form.sort = column;
    filter();
}
function exportRows() {
    exportEndingRows('ending-scheduled-games.csv', [
        [
            'ID',
            'League',
            'Team A',
            'Team B',
            'Schedule',
            'Betting',
            'Status',
            'Enabled at',
            'Created at',
        ],
        ...props.games.data.map((game) => [
            game.id,
            game.league,
            game.team_a_name,
            game.team_b_name,
            displayDate(game.schedule),
            game.betting,
            game.status,
            displayDate(game.enabled_betting_at),
            displayDate(game.created_at),
        ]),
    ]);
}

const tableHeaders: DataTableHeader[] = [
    { title: 'Action', key: 'actions', sortable: false },
    { title: 'League', key: 'league', sortable: true },
    { title: 'Game', key: 'team_a_name', sortable: true },
    { title: 'Schedule', key: 'schedule', sortable: true },
    { title: 'Betting', key: 'betting', sortable: true },
    { title: 'Status', key: 'status', sortable: true },
    { title: 'Enabled at', key: 'enabled_betting_at', sortable: true },
    { title: 'Created at', key: 'created_at', sortable: true },
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
        {
            key: 'league_id',
            options: props.leagues.map((league) => ({ title: league.league, value: league.id })),
        },
        { key: 'date_range', removable: false },
    ],
    (changes) => {
        Object.assign(form, changes);
        filter();
    },
    () => {
        form.search = '';
        refresh();
    },
);
</script>

<template>
    <EndingShell title="Scheduled Games" :migration-ready="migrationReady">
        <v-card>
            <EndingToolbar
                :filters-open="filtersOpen"
                :active-filters="activeFilters"
                @remove-filter="removeFilter"
                @reset-filters="resetTableFilters"
                :total="games.total"
                filterable
                filter-label="Filter games"
                @filter="openFilters"
                v-model="form.search"
                :create-label="canCreate ? 'Create Game' : undefined"
                :disabled="!migrationReady"
                exportable
                @create="create = true"
                @refresh="refresh"
                @search="search"
                @export="exportRows"
            >
                <v-btn
                    size="small"
                    variant="text"
                    aria-label="Historical Logs"
                    title="Historical Logs"
                    icon="mdi-history"
                    :href="EndingGameController.history.url()"
                    @click.prevent="router.visit(EndingGameController.history())"
                />
            </EndingToolbar>
            <v-divider />
            <AdminDataTable
                :headers="tableHeaders"
                :items="games.data"
                server
                :toolbar="false"
                title="Games"
            >
                <template #headers>
                    <tr>
                        <th class="text-center">Action</th>
                        <th>League</th>
                        <th>Game</th>
                        <th>
                            <button class="ending-sort" @click="sort('schedule')">
                                Schedule
                                <v-icon
                                    :icon="
                                        form.sort === 'schedule' && form.direction === 'asc'
                                            ? 'mdi-arrow-up'
                                            : 'mdi-arrow-down'
                                    "
                                    size="12"
                                />
                            </button>
                        </th>
                        <th>Betting</th>
                        <th>Status</th>
                        <th>
                            <button class="ending-sort" @click="sort('enabled_betting_at')">
                                Enabled at
                                <v-icon
                                    :icon="
                                        form.sort === 'enabled_betting_at' &&
                                        form.direction === 'asc'
                                            ? 'mdi-arrow-up'
                                            : 'mdi-arrow-down'
                                    "
                                    size="12"
                                />
                            </button>
                        </th>
                        <th>
                            <button class="ending-sort" @click="sort('created_at')">
                                Created at
                                <v-icon
                                    :icon="
                                        form.sort === 'created_at' && form.direction === 'asc'
                                            ? 'mdi-arrow-up'
                                            : 'mdi-arrow-down'
                                    "
                                    size="12"
                                />
                            </button>
                        </th>
                    </tr>
                </template>
                <template #item="{ item: game }"
                    ><tr :key="game.id">
                        <td class="text-center">
                            <v-btn
                                icon="mdi-text-box-search-outline"
                                variant="text"
                                color="primary"
                                size="x-small"
                                :aria-label="'View game ' + game.id"
                                :href="EndingGameController.show.url(game.id)"
                                @click.prevent="router.visit(EndingGameController.show(game.id))"
                            />
                        </td>
                        <td>{{ game.league || '—' }}</td>
                        <td>
                            <Link
                                :href="EndingGameController.show.url(game.id)"
                                class="ending-game-link"
                                ><span class="ending-inline-team"
                                    ><EntityLogo
                                        :src="game.team_a_logo"
                                        :name="game.team_a_name"
                                        :size="26"
                                    />{{ game.team_a_name || 'Team #' + game.team_a }}</span
                                ><span class="ending-versus">vs</span
                                ><span class="ending-inline-team"
                                    ><EntityLogo
                                        :src="game.team_b_logo"
                                        :name="game.team_b_name"
                                        :size="26"
                                    />{{ game.team_b_name || 'Team #' + game.team_b }}</span
                                ></Link
                            >
                        </td>
                        <td class="text-no-wrap">{{ displayDate(game.schedule) }}</td>
                        <td>
                            <v-chip
                                size="x-small"
                                :color="game.betting === 'Enabled' ? 'success' : 'default'"
                                >{{ game.betting }}</v-chip
                            >
                        </td>
                        <td class="text-no-wrap">
                            <v-chip
                                size="x-small"
                                :color="
                                    game.game_status_id === 5
                                        ? 'success'
                                        : game.game_status_id
                                          ? 'primary'
                                          : 'warning'
                                "
                                >{{ game.status }}</v-chip
                            >
                        </td>
                        <td class="text-no-wrap">{{ displayDate(game.enabled_betting_at) }}</td>
                        <td class="text-no-wrap">{{ displayDate(game.created_at) }}</td>
                    </tr></template
                >
                <template #no-data> No games found. </template>
            </AdminDataTable>
            <EndingPager :page="games" selectable />
        </v-card>
        <AdminFilterDialog v-model="filtersOpen" @apply="applyFilters" @reset="resetTableFilters">
            <v-autocomplete
                v-model="draft.league_id"
                :items="leagues"
                item-title="league"
                item-value="id"
                label="League"
                variant="outlined"
                density="compact"
                clearable
                hide-details="auto"
            />
            <v-select
                v-model="draft.status"
                label="Status"
                :items="[
                    { title: 'All statuses', value: '' },
                    { title: 'Scheduled', value: 'scheduled' },
                    { title: 'Ongoing', value: 'ongoing' },
                    { title: 'Finished', value: 'finished' },
                ]"
                hide-details
            />
            <v-row class="mt-2">
                <v-col cols="12" sm="6">
                    <v-date-input
                        v-model="startDate"
                        label="Schedule Date Start"
                        input-format="yyyy-MM-dd"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details="auto"
                        :rules="[(value) => !!value || 'Schedule Date Start is required.']"
                    />
                </v-col>
                <v-col cols="12" sm="6">
                    <v-date-input
                        v-model="endDate"
                        label="Schedule Date End (Optional)"
                        input-format="yyyy-MM-dd"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details="auto"
                        :min="startDate || undefined"
                        :rules="[
                            (value) =>
                                !value ||
                                !draft.date_from ||
                                dateAdapter.toISO(value) >= draft.date_from ||
                                'Schedule Date End must be on or after Schedule Date Start.',
                        ]"
                    />
                </v-col>
            </v-row>
            <v-alert
                v-if="Object.keys(page.props.errors).length"
                type="error"
                variant="tonal"
                role="alert"
                class="mt-3"
                >{{ Object.values(page.props.errors).join(' ') }}</v-alert
            >
        </AdminFilterDialog>
        <GameForm v-model="create" :leagues="leagues" :teams="teams" :presets="presets" />
    </EndingShell>
</template>
