<script setup lang="ts">
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import { useTableFilters } from '@/composables/useTableFilters';
import AdminDataTable from '@/components/AdminDataTable.vue';
import { reactive, ref, watch, onBeforeUnmount } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { index, show } from '@/actions/App/Http/Controllers/OtsoPanaloController';
import { usePermissions } from '@/composables/usePermissions';
import OtsoShell from '@/components/otso/OtsoShell.vue';
import GameForm from '@/components/otso/GameForm.vue';
import EndingToolbar from '@/components/ending/EndingToolbar.vue';
import { exportTableItems } from '@/composables/useTableExport';
import EndingPager from '@/components/ending/EndingPager.vue';
import { money } from '@/types/ending';
import { displayOtsoDate as displayDate } from '@/types/otso';
import type { PageResult } from '@/types/ending';
import type { OtsoGame, OtsoConfig, AvailableGame } from '@/types/otso';

const props = defineProps<{
    games: PageResult<OtsoGame>;
    filters: Record<string, string>;
    migrationReady: boolean;
    activeConfig: OtsoConfig | null;
    availableGames: AvailableGame[];
}>();
const { can } = usePermissions();
const create = ref(false);
const filters = reactive({
    search: props.filters.search || '',
    status: props.filters.status || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
    sort: props.filters.sort || 'created_at',
    direction: props.filters.direction || 'desc',
});
const columns = [
    { key: 'id', label: 'ID' },
    { key: 'schedule', label: 'Schedule' },
    { key: 'betting_deadline', label: 'Betting Deadline' },
    { key: 'betting', label: 'Betting' },
    { key: 'game_status', label: 'Status' },
    { key: 'bet_amount', label: 'Bet Amount' },
    { key: 'jackpot_prize', label: 'Jackpot Prize' },
    { key: 'created_at', label: 'Created At' },
] as const;
watch(
    () => props.filters,
    (value) =>
        Object.assign(filters, {
            search: value.search || '',
            status: value.status || '',
            date_from: value.date_from || '',
            date_to: value.date_to || '',
            sort: value.sort || 'created_at',
            direction: value.direction || 'desc',
        }),
);
let timer: ReturnType<typeof setTimeout> | undefined;
function filter() {
    clearTimeout(timer);
    router.get(
        index.url(),
        { ...filters, per_page: props.games.per_page },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => (filterOpen.value = false),
            replace: true,
        },
    );
}
function sort(column: string) {
    filters.direction = filters.sort === column && filters.direction === 'desc' ? 'asc' : 'desc';
    filters.sort = column;
    filter();
}
function search() {
    clearTimeout(timer);
    timer = setTimeout(filter, 300);
}
onBeforeUnmount(() => clearTimeout(timer));

const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => [
        'search',
        {
            key: 'status',
            resetValue: '',
            options: [
                { title: 'Not yet started', value: 1 },
                { title: 'Started', value: 2 },
                { title: 'Finished', value: 3 },
                { title: 'Cancelled', value: 4 },
            ],
        },
        'date_range',
    ],
    (changes) => {
        Object.assign(filters, changes);
        filter();
    },
    undefined,
);
const filterOpen = ref(false);
const draft = reactive({ ...filters });
function openFilters() {
    Object.assign(draft, filters);
    filterOpen.value = true;
}
function applyFilters() {
    Object.assign(filters, draft);
    filter();
}
</script>

<template>
    <OtsoShell title="Games" :migration-ready="migrationReady">
        <v-card class="otso-games-card">
            <EndingToolbar
                :filters-open="filterOpen"
                filterable
                @filter="openFilters"
                :active-filters="activeFilters"
                @remove-filter="removeFilter"
                @reset-filters="resetTableFilters"
                :total="games.total"
                v-model="filters.search"
                exportable
                @export="
                    exportTableItems(
                        'otso-games.csv',
                        games.data,
                        columns.map((column) => ({ key: column.key, title: column.label })),
                    )
                "
                :create-label="can('Create Otso Panalo') ? 'Create Game' : undefined"
                :disabled="!migrationReady"
                @create="create = true"
                @refresh="filter"
                @search="search"
            >
            </EndingToolbar>
            <v-divider />
            <AdminDataTable
                :headers="[
                    { title: 'Action', key: 'actions', sortable: false },
                    ...columns.map((column) => ({ key: column.key, title: column.label })),
                    { title: 'Game', key: 'team_a_name' },
                ]"
                :items="games.data"
                server
                :toolbar="false"
                title="Games"
            >
                <template #headers>
                    <tr>
                        <th class="text-center">Action</th>
                        <template v-for="column in columns" :key="column.key">
                            <th
                                :aria-sort="
                                    filters.sort === column.key
                                        ? filters.direction === 'asc'
                                            ? 'ascending'
                                            : 'descending'
                                        : 'none'
                                "
                            >
                                <v-btn
                                    class="otso-sort pa-0 font-weight-regular"
                                    variant="text"
                                    size="small"
                                    density="compact"
                                    min-width="0"
                                    :aria-label="'Sort by ' + column.label"
                                    @click="sort(column.key)"
                                >
                                    {{ column.label }}
                                    <v-icon
                                        :icon="
                                            filters.sort === column.key &&
                                            filters.direction === 'desc'
                                                ? 'mdi-arrow-down'
                                                : 'mdi-arrow-up'
                                        "
                                        size="12"
                                        class="ml-1"
                                        :color="filters.sort === column.key ? 'black' : 'grey'"
                                    />
                                </v-btn>
                            </th>
                            <th v-if="column.key === 'id'">Game</th>
                        </template>
                    </tr>
                </template>
                <template #item="{ item: game }"
                    ><tr :key="game.id">
                        <td class="text-center otso-action-cell">
                            <v-btn
                                :href="show.url(game.id)"
                                icon="mdi-text-box-search-outline"
                                variant="text"
                                color="primary"
                                size="x-small"
                                :aria-label="'View game ' + game.id"
                                :title="'View game ' + game.id"
                                @click.prevent="router.visit(show(game.id))"
                            />
                        </td>
                        <td>{{ game.id }}</td>
                        <td class="otso-matchup-cell">
                            <Link
                                :href="show(game.id)"
                                class="otso-matchup-link d-flex flex-column ga-1 text-decoration-none"
                            >
                                <span class="font-weight-medium">{{ game.league }}</span>
                                <span
                                    >{{ game.team_a_name }} {{ game.team_a_alias }}
                                    <span class="font-italic px-2">vs</span> {{ game.team_b_name }}
                                    {{ game.team_b_alias }}</span
                                >
                            </Link>
                        </td>
                        <td>{{ displayDate(game.schedule) }}</td>
                        <td>{{ displayDate(game.betting_deadline) }}</td>
                        <td>
                            <v-chip
                                size="x-small"
                                variant="tonal"
                                :color="game.betting === 'Enabled' ? 'success' : 'orange'"
                                >{{ game.betting }}</v-chip
                            >
                        </td>
                        <td>
                            <v-chip
                                size="x-small"
                                variant="tonal"
                                :color="
                                    Number(game.game_status_id) === 4
                                        ? 'error'
                                        : Number(game.game_status_id) === 3
                                          ? 'success'
                                          : 'blue'
                                "
                                >{{ game.game_status }}</v-chip
                            >
                        </td>
                        <td>PHP {{ money(game.bet_amount) }}</td>
                        <td>PHP {{ money(game.jackpot_prize) }}</td>
                        <td>{{ displayDate(game.created_at) }}</td>
                    </tr></template
                >
                <template #no-data>No games found.</template>
            </AdminDataTable>
            <EndingPager
                :page="games"
                :page-sizes="[10, 25, 50, 100]"
                selectable
                class="otso-pager border-t py-2"
            />
        </v-card>
        <v-dialog v-model="create" max-width="760">
            <GameForm
                v-if="create"
                :available-games="availableGames"
                :active-config="activeConfig"
                @close="create = false"
                @saved="create = false"
            />
        </v-dialog>
        <AdminFilterDialog v-model="filterOpen" @apply="applyFilters" @reset="resetTableFilters">
            <v-row dense>
                <v-col cols="12" sm="6">
                    <v-select
                        v-model="draft.status"
                        label="Status"
                        :items="[
                            { title: 'All statuses', value: '' },
                            { title: 'Not yet started', value: '1' },
                            { title: 'Started', value: '2' },
                            { title: 'Finished', value: '3' },
                            { title: 'Cancelled', value: '4' },
                        ]"
                        hide-details
                    />
                </v-col>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.date_from"
                        type="date"
                        label="Date from"
                        hide-details
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.date_to"
                        type="date"
                        label="Date to"
                        :min="draft.date_from || undefined"
                        hide-details
                /></v-col>
            </v-row> </AdminFilterDialog
    ></OtsoShell>
</template>

<style scoped>
.otso-games-card :deep(.ending-toolbar) {
    min-height: 58px;
}
.otso-games-card :deep([aria-label='Refresh']) {
    color: #757575;
}
.otso-games-card :deep(.ending-search .v-field__input) {
    min-height: 36px;
    padding-block: 6px;
}
.otso-games-table {
    --v-table-row-height: 44px;
    --v-table-header-height: 38px;
}
.otso-games-table :deep(table) {
    min-width: 1450px;
}
.otso-games-table :deep(th),
.otso-games-table :deep(td) {
    padding-inline: 20px !important;
    white-space: nowrap;
}
.otso-games-table :deep(td) {
    padding-block: 3px !important;
}
.otso-action-cell {
    width: 90px;
}
.otso-matchup-cell {
    min-width: 310px;
}
.otso-matchup-link {
    color: inherit;
    line-height: 1.5;
}
.otso-matchup-link:hover {
    color: rgb(var(--v-theme-primary));
}
.otso-sort {
    letter-spacing: normal;
}
.otso-pager :deep(.v-btn) {
    height: 32px !important;
}
</style>
