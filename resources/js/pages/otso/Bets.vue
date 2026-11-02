<script setup lang="ts">
import { useTableFilters } from '@/composables/useTableFilters';
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { exportTableItems } from '@/composables/useTableExport';
import AdminDataTable from '@/components/AdminDataTable.vue';
import { computed, reactive, ref, watch, onBeforeUnmount } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    bets as betsRoute,
    show,
    pdf,
    exportBets,
} from '@/actions/App/Http/Controllers/OtsoPanaloController';
import { usePermissions } from '@/composables/usePermissions';
import OtsoShell from '@/components/otso/OtsoShell.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import { money } from '@/types/ending';
import type { PageResult } from '@/types/ending';
import { quarters, displayOtsoDate as displayDate } from '@/types/otso';
import type { OtsoBet, OtsoGame } from '@/types/otso';

interface BetFilters {
    search: string;
    game_id: number | null;
    filter_by: string;
    date_from: string;
    date_to: string;
    sort: string;
    direction: string;
}
const props = defineProps<{
    bets: PageResult<OtsoBet>;
    games: OtsoGame[];
    filters: Record<string, string>;
    migrationReady: boolean;
}>();
const { can } = usePermissions();
const selected = ref<OtsoBet | null>(null);
const filterDialog = ref(false);
const loading = ref(false);
const filterErrors = ref<Record<string, string>>({});
function filterValues(values: Record<string, string>): BetFilters {
    return {
        search: values.search || '',
        game_id: Number(values.game_id) || null,
        filter_by: values.filter_by || 'Bet Date',
        date_from: values.date_from || '',
        date_to: values.date_to || '',
        sort: values.sort || 'created_at',
        direction: values.direction || 'desc',
    };
}
const filters = reactive(filterValues(props.filters));
const draftFilters = reactive({
    game_id: filters.game_id,
    filter_by: filters.filter_by,
    date_from: filters.date_from,
    date_to: filters.date_to,
});
watch(
    () => props.filters,
    (values) => Object.assign(filters, filterValues(values)),
);
const gameOptions = computed(() =>
    props.games.map((game) => ({
        title: '#' + game.ending_game_id + ' / ' + game.team_a_name + ' vs ' + game.team_b_name,
        value: game.id,
    })),
);
const headers: { key: string; label: string; sortable?: boolean; align?: 'end' }[] = [
    { key: 'id', label: 'ID', sortable: true },
    { key: 'username', label: 'Player Username' },
    { key: 'game', label: 'Game' },
    { key: 'bet_type', label: 'Bet Type' },
    { key: 'name', label: 'Ticket Name' },
    { key: 'combinations', label: 'Combinations' },
    { key: 'amount', label: 'Amount', sortable: true, align: 'end' },
    { key: 'prize_amount', label: 'Prize Amount', align: 'end' },
    { key: 'refunded', label: 'Refunded', sortable: true },
    { key: 'created_at', label: 'Bet At', sortable: true },
];
let timer: ReturnType<typeof setTimeout> | undefined;
function loadBets(values: BetFilters = filters, closeDialog = false) {
    clearTimeout(timer);
    router.get(
        betsRoute.url(),
        { ...values, per_page: props.bets.per_page },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
            onError: (errors) => {
                filterErrors.value = errors;
            },
            onSuccess: () => {
                filterErrors.value = {};
                if (closeDialog) filterDialog.value = false;
            },
        },
    );
}
function search() {
    clearTimeout(timer);
    timer = setTimeout(() => loadBets(), 300);
}
function openFilters() {
    clearTimeout(timer);
    Object.assign(draftFilters, {
        game_id: filters.game_id,
        filter_by: filters.filter_by,
        date_from: filters.date_from,
        date_to: filters.date_to,
    });
    filterErrors.value = {};
    filterDialog.value = true;
}
function resetFilters() {
    Object.assign(draftFilters, {
        game_id: null,
        filter_by: 'Bet Date',
        date_from: '',
        date_to: '',
    });
    filterErrors.value = {};
    loadBets({ ...filters, ...draftFilters, search: '' }, true);
}
function applyFilters() {
    loadBets({ ...filters, ...draftFilters }, true);
}
function sort(column: string) {
    loadBets({
        ...filters,
        sort: column,
        direction: filters.sort === column && filters.direction === 'desc' ? 'asc' : 'desc',
    });
}
onBeforeUnmount(() => clearTimeout(timer));
const exportHeaders = [
    { title: 'ID', key: 'id' },
    { title: 'Player', key: 'username', value: (bet: OtsoBet) => bet.username || bet.player_name },
    { title: 'Ending game', key: 'ending_game_id' },
    { title: 'League', key: 'league' },
    { title: 'Team A', key: 'team_a_name' },
    { title: 'Team B', key: 'team_b_name' },
    { title: 'Bet type', key: 'bet_type' },
    { title: 'Ticket', key: 'name' },
    ...quarters.map((quarter) => ({ title: quarter.label, key: quarter.combination })),
    { title: 'Amount', key: 'amount' },
    { title: 'Prize', key: 'prize_amount' },
    { title: 'Refunded', key: 'refunded' },
    { title: 'Bet at', key: 'created_at' },
];

const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => ['search', { key: 'game_id', options: gameOptions.value }, 'filter_by', 'date_range'],
    (changes) => {
        Object.assign(filters, changes);
        loadBets(filters, true);
    },
    resetFilters,
);
</script>

<template>
    <OtsoShell title="Bets" :migration-ready="migrationReady">
        <v-card class="otso-bets-card">
            <AdminTableToolbar
                :filters-open="filterDialog"
                :active-filters="activeFilters"
                @remove-filter="removeFilter"
                @reset-filters="resetTableFilters"
                :total="bets.total"
                v-model="filters.search"
                search-label="Search bets"
                refresh-label="Refresh bets"
                :exports="[
                    { title: 'All matching bets (CSV)', href: exportBets.url({ query: filters }) },
                    {
                        title: 'Current page (CSV)',
                        action: () => exportTableItems('otso-bets.csv', bets.data, exportHeaders),
                    },
                ]"
                @refresh="loadBets()"
                @search="search"
                :loading="loading"
                filterable
                @filter="openFilters"
                filter-label="Filters"
            >
                <template #actions
                    ><v-btn
                        v-if="filters.game_id && can('Ending Game PDF Generation')"
                        :href="pdf.url(filters.game_id)"
                        prepend-icon="mdi-file-pdf-box"
                        variant="text"
                        color="primary"
                        size="small"
                        >Generate Game Bets PDF</v-btn
                    ></template
                ></AdminTableToolbar
            >
            <v-divider />

            <AdminDataTable
                aria-label="Bet records"
                :headers="headers.map((header) => ({ ...header, title: header.label }))"
                :items="bets.data"
                server
                :toolbar="false"
                title="Bets"
            >
                <template #headers>
                    <tr>
                        <th
                            v-for="header in headers"
                            :key="header.key"
                            :class="header.align === 'end' ? 'text-end' : 'text-start'"
                            :aria-sort="
                                header.sortable
                                    ? filters.sort === header.key
                                        ? filters.direction === 'asc'
                                            ? 'ascending'
                                            : 'descending'
                                        : 'none'
                                    : undefined
                            "
                        >
                            <v-btn
                                v-if="header.sortable"
                                variant="text"
                                size="small"
                                density="compact"
                                min-width="0"
                                class="otso-bets-sort pa-0 font-weight-regular"
                                :aria-label="'Sort by ' + header.label"
                                :disabled="loading"
                                @click="sort(header.key)"
                            >
                                {{ header.label
                                }}<v-icon
                                    :icon="
                                        filters.sort === header.key && filters.direction === 'desc'
                                            ? 'mdi-arrow-down'
                                            : 'mdi-arrow-up'
                                    "
                                    :color="filters.sort === header.key ? 'black' : 'grey'"
                                    size="12"
                                    class="ml-1"
                                />
                            </v-btn>
                            <template v-else>{{ header.label }}</template>
                        </th>
                    </tr>
                </template>
                <template #item="{ item: bet }"
                    ><tr :key="bet.id">
                        <td>
                            <v-btn
                                variant="text"
                                color="primary"
                                density="compact"
                                size="small"
                                height="24"
                                min-width="0"
                                class="pa-0"
                                :aria-label="'View bet ' + bet.id"
                                @click="selected = bet"
                                >{{ bet.id }}</v-btn
                            >
                        </td>
                        <td>{{ bet.username || bet.player_name || 'Unlinked player' }}</td>
                        <td class="otso-bets-game">
                            <Link
                                :href="show(bet.game_id)"
                                class="otso-bets-game-link d-flex flex-column ga-1 text-decoration-none"
                            >
                                <span class="font-weight-medium">{{ bet.league || '-' }}</span>
                                <span
                                    >#{{ bet.ending_game_id }} <span class="mx-2 text-grey">|</span>
                                    {{ bet.team_a_name }} {{ bet.team_a_alias }}
                                    <span class="font-italic mx-2">vs</span> {{ bet.team_b_name }}
                                    {{ bet.team_b_alias }}</span
                                >
                            </Link>
                        </td>
                        <td>{{ bet.bet_type || '-' }}</td>
                        <td>{{ bet.name || '-' }}</td>
                        <td class="otso-bets-combinations">
                            <div class="d-flex flex-column ga-1 py-2">
                                <div
                                    v-for="quarter in quarters"
                                    :key="quarter.number"
                                    class="d-flex align-center ga-2"
                                    :aria-label="quarter.label"
                                >
                                    <span
                                        :class="{
                                            'text-success font-weight-bold':
                                                bet[quarter.status] === 'Win',
                                        }"
                                        >Q{{ quarter.number }} Bet:
                                        {{ bet[quarter.combination] || '-' }}</span
                                    >
                                    <span>Result: {{ bet[quarter.result] || '-' }}</span>
                                    <v-chip
                                        size="x-small"
                                        variant="tonal"
                                        :color="
                                            bet[quarter.status] === 'Win'
                                                ? 'success'
                                                : bet[quarter.status] === 'Lose'
                                                  ? 'error'
                                                  : 'blue-grey'
                                        "
                                        >{{ bet[quarter.status] || 'Pending' }}</v-chip
                                    >
                                </div>
                            </div>
                        </td>
                        <td class="text-end">{{ money(bet.amount) }}</td>
                        <td class="text-end">{{ money(bet.prize_amount) }}</td>
                        <td>
                            <v-chip
                                size="x-small"
                                variant="tonal"
                                :color="bet.refunded === 'Yes' ? 'error' : 'success'"
                                >{{ bet.refunded }}</v-chip
                            >
                        </td>
                        <td>{{ displayDate(bet.created_at) }}</td>
                    </tr></template
                >
                <template #no-data> No bets found for the selected filters. </template>
            </AdminDataTable>
            <EndingPager
                :page="bets"
                selectable
                :page-sizes="[10, 20, 25, 50, 100, 500]"
                class="border-t"
            />
        </v-card>
        <AdminFilterDialog
            v-model="filterDialog"
            :loading="loading"
            @apply="applyFilters"
            @reset="resetTableFilters"
        >
            <v-row gap="16">
                <v-col cols="12" sm="6"
                    ><v-select
                        v-model="draftFilters.filter_by"
                        :items="['Bet Date', 'Game Schedule']"
                        label="Filter By"
                        variant="outlined"
                        density="compact"
                        :error-messages="filterErrors.filter_by"
                        hide-details="auto"
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-autocomplete
                        v-model="draftFilters.game_id"
                        :items="gameOptions"
                        label="Ending Game"
                        variant="outlined"
                        density="compact"
                        clearable
                        :error-messages="filterErrors.game_id"
                        hide-details="auto"
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draftFilters.date_from"
                        type="date"
                        :label="draftFilters.filter_by + ' From'"
                        :error-messages="filterErrors.date_from"
                        hide-details="auto"
                        clearable
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draftFilters.date_to"
                        type="date"
                        :label="draftFilters.filter_by + ' To (Optional)'"
                        :error-messages="filterErrors.date_to"
                        hide-details="auto"
                        clearable
                /></v-col>
            </v-row>
        </AdminFilterDialog>
        <v-dialog :model-value="!!selected" max-width="600" @update:model-value="selected = null"
            ><v-card v-if="selected"
                ><v-card-title>Bet #{{ selected.id }}</v-card-title
                ><v-card-text
                    ><p>
                        Player: {{ selected.username || selected.player_name || 'Unlinked player' }}
                    </p>
                    <p>Ticket: {{ selected.name || '—' }}</p>
                    <p>Bet type: {{ selected.bet_type }}</p>
                    <p>Amount: PHP {{ money(selected.amount) }}</p>
                    <p>Prize: PHP {{ money(selected.prize_amount) }}</p>
                    <p>Refunded: {{ selected.refunded }}</p>
                    <p>Legacy ID: {{ selected.legacy_id || '—' }}</p>
                    <p v-for="quarter in quarters" :key="quarter.number">
                        {{ quarter.label }}: {{ selected[quarter.combination] || '—' }}
                        {{ selected[quarter.status] || '' }}
                    </p></v-card-text
                ><v-card-actions
                    ><v-btn @click="selected = null">Close</v-btn></v-card-actions
                ></v-card
            ></v-dialog
        >
    </OtsoShell>
</template>

<style scoped>
.otso-bets-search :deep(.v-field__input) {
    min-height: 36px;
    padding-block: 6px;
}
.otso-bets-table :deep(table) {
    min-width: 1500px;
}
.otso-bets-table :deep(th),
.otso-bets-table :deep(td) {
    white-space: nowrap;
}
.otso-bets-game {
    min-width: 350px;
}
.otso-bets-game-link {
    color: inherit;
}
.otso-bets-game-link:hover {
    color: rgb(var(--v-theme-primary));
}
.otso-bets-combinations {
    min-width: 280px;
}
.otso-bets-sort {
    letter-spacing: normal;
}
</style>
