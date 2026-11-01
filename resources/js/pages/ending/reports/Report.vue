<script setup lang="ts">
import {
    index,
    exportMethod as exportReport,
} from '@/actions/App/Http/Controllers/EndingReportController';
import { show as showGame } from '@/actions/App/Http/Controllers/EndingGameController';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import { usePermissions } from '@/composables/usePermissions';
import type { PageResult } from '@/types/ending';

type Row = Record<string, string | number | null> & { id: number | string };
type Column = { key: string; title: string; format: string };
const props = defineProps<{
    report: string;
    title: string;
    columns: Column[];
    records: PageResult<Row>;
    filters: Record<string, string | number | null>;
    totals?: Record<string, string | number>;
    leagues: { id: number; league: string }[];
    teams: { id: number; league_id: number; team_name: string }[];
    today: string;
}>();
const page = usePage();
const { can } = usePermissions();
const loading = ref(false);
const form = reactive({
    date_from: '',
    date_to: '',
    search: '' as string | null,
    league_id: null as number | null,
    team_id: null as number | null,
    player_id: null as string | number | null,
    game_id: null as string | number | null,
    card_id: null as string | number | null,
    bet_type_id: null as number | null,
    status: 'All',
    cashier_status: 'Approved',
    filter_by: 'scheduled',
    period: 'daily',
    sort: '',
    direction: 'desc',
    per_page: 25,
});
watch(
    () => props.filters,
    (filters) => Object.assign(form, filters),
    { immediate: true },
);
const isCashier = computed(() => ['cash-in', 'cash-out'].includes(props.report));
const isAgent = computed(() => props.report === 'master-agents-token');
const isGameReport = computed(
    () => !isCashier.value && !isAgent.value && props.report !== 'transactional',
);
const teamsInLeague = computed(() =>
    props.teams.filter((team) => !form.league_id || team.league_id === Number(form.league_id)),
);
const moneyColumns = computed(() => props.columns.filter((column) => column.format === 'money'));
const summaryLoading = computed(() => props.totals === undefined);
let timer: ReturnType<typeof setTimeout> | undefined;
function visit(targetPage = 1) {
    clearTimeout(timer);
    router.get(
        index.url(props.report),
        { ...form, page: targetPage },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['records', 'filters', 'totals'],
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}
function search() {
    clearTimeout(timer);
    timer = setTimeout(() => visit(), 350);
}
function sort(key: string) {
    form.direction = form.sort === key && form.direction === 'asc' ? 'desc' : 'asc';
    form.sort = key;
    visit();
}
function reset() {
    router.get(index.url(props.report), {}, { preserveScroll: true });
}
function exportUrl(scope: 'page' | 'all', format: 'csv' | 'pdf' = 'csv') {
    return exportReport.url(props.report, {
        query: { ...props.filters, page: props.records.current_page, scope, format },
    });
}
function money(value: string | number | null | undefined) {
    return Number(value ?? 0).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
}
function display(value: string | number | null | undefined, format: string) {
    if (value === null || value === undefined || value === '') return '—';
    if (format === 'money') return money(value);
    if (format === 'date') {
        const raw = String(value).replace(' ', 'T');
        const date = new Date(/[Z+-]\d{0,2}:?\d{0,2}$/.test(raw) ? raw : `${raw}Z`);
        return Number.isNaN(date.getTime())
            ? String(value)
            : date.toLocaleString('en-PH', {
                  timeZone: 'Asia/Manila',
                  dateStyle: 'medium',
                  timeStyle: 'short',
              });
    }
    return String(value);
}
function gameId(row: Row): number | null {
    const id = row.game_id ?? (['game', 'payouts'].includes(props.report) ? row.id : null);
    return id === null ? null : Number(id);
}
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div>
        <Head :title="`${title} — Ending Reports`" />
        <div class="ending-page">
            <div class="ending-breadcrumb">Ending Reports</div>
            <h1 class="ending-page-title">{{ title }}</h1>
            <p class="text-body-2 text-medium-emphasis mb-4">
                <template v-if="isAgent"
                    >Current master agent token balances and assigned players. Bet activity uses the
                    selected dates. Agent records come from the legacy system.</template
                >
                <template v-else-if="isCashier"
                    >Cashier requests grouped by player and request date, using the selected
                    approval status.</template
                >
                <template v-else-if="report === 'transactional'"
                    >Wallet transaction history excluding Cash In transactions.</template
                >
                <template v-else
                    >Ending game activity and released cash payouts. Noncash prizes are excluded
                    from payout amounts.</template
                >
                Dates use Philippine time.
            </p>
            <v-card class="mb-4">
                <div class="d-flex align-center flex-wrap ga-3 pa-4">
                    <v-icon icon="mdi-chart-box-outline" color="primary" />
                    <span class="text-subtitle-1 font-weight-medium">{{ title }} Report</span>
                    <v-chip size="small" variant="tonal"
                        >{{ records.total.toLocaleString() }} records</v-chip
                    >
                    <v-spacer />
                    <v-btn
                        icon="mdi-refresh"
                        variant="text"
                        aria-label="Refresh report"
                        :loading="loading"
                        @click="visit(records.current_page)"
                    />
                    <v-menu>
                        <template #activator="{ props: menuProps }">
                            <v-btn
                                v-bind="menuProps"
                                prepend-icon="mdi-download"
                                variant="tonal"
                                :disabled="loading"
                                >Export</v-btn
                            >
                        </template>
                        <v-list>
                            <v-list-item
                                title="CSV · All matching records"
                                subtitle="Opens in Excel"
                                :href="exportUrl('all')"
                            />
                            <v-list-item title="CSV · Current page" :href="exportUrl('page')" />
                            <v-list-item
                                title="PDF · Current page"
                                :href="exportUrl('page', 'pdf')"
                            />
                        </v-list>
                    </v-menu>
                </div>
                <v-divider />
                <form class="report-filters pa-4" @submit.prevent="visit()">
                    <v-text-field
                        v-model="form.date_from"
                        type="date"
                        label="Date from"
                        :error-messages="page.props.errors.date_from"
                        hide-details="auto"
                        required
                    />
                    <v-text-field
                        v-model="form.date_to"
                        type="date"
                        label="Date to"
                        :min="String(form.date_from)"
                        :error-messages="page.props.errors.date_to"
                        hide-details="auto"
                        required
                    />
                    <template v-if="isGameReport">
                        <v-select
                            v-model="form.league_id"
                            label="League"
                            :items="leagues"
                            item-title="league"
                            item-value="id"
                            clearable
                            hide-details
                            @update:model-value="form.team_id = null"
                        />
                        <v-select
                            v-model="form.team_id"
                            label="Team"
                            :items="teamsInLeague"
                            item-title="team_name"
                            item-value="id"
                            clearable
                            hide-details
                        />
                        <v-select
                            v-model="form.status"
                            label="Game status"
                            :items="['All', 'Not Started', 'Ongoing', 'Finished']"
                            hide-details
                        />
                        <v-select
                            v-model="form.filter_by"
                            label="Date basis"
                            :items="[
                                { title: 'Scheduled date', value: 'scheduled' },
                                {
                                    title: report === 'progress' ? 'Bet / payout date' : 'Bet date',
                                    value: 'bet',
                                },
                            ]"
                            hide-details
                        />
                        <v-select
                            v-if="['bets', 'game-card', 'cards'].includes(report)"
                            v-model="form.bet_type_id"
                            label="Bet type"
                            :items="[
                                { title: 'Traditional', value: 1 },
                                { title: 'Per Quarter', value: 2 },
                            ]"
                            clearable
                            hide-details
                        />
                    </template>
                    <v-select
                        v-if="report === 'progress'"
                        v-model="form.period"
                        label="Group by"
                        :items="[
                            { title: 'Daily', value: 'daily' },
                            { title: 'Weekly', value: 'weekly' },
                            { title: 'Monthly', value: 'monthly' },
                            { title: 'Yearly', value: 'yearly' },
                        ]"
                        hide-details
                    />
                    <v-select
                        v-if="isCashier"
                        v-model="form.cashier_status"
                        label="Approval status"
                        :items="[
                            'All',
                            'Pending',
                            'Verified',
                            'Approved',
                            'Disapproved',
                            'Rejected',
                        ]"
                        hide-details
                    />
                    <v-text-field
                        v-if="['bets', 'transactional', 'cash-in', 'cash-out'].includes(report)"
                        v-model="form.player_id"
                        type="number"
                        min="1"
                        label="Player ID"
                        hide-details="auto"
                        :error-messages="page.props.errors.player_id"
                        clearable
                    />
                    <v-text-field
                        v-if="isGameReport"
                        v-model="form.game_id"
                        type="number"
                        min="1"
                        label="Game ID"
                        hide-details="auto"
                        :error-messages="page.props.errors.game_id"
                        clearable
                    />
                    <v-text-field
                        v-if="['bets', 'game-card'].includes(report)"
                        v-model="form.card_id"
                        type="number"
                        min="1"
                        label="Card number"
                        hide-details="auto"
                        :error-messages="page.props.errors.card_id"
                        clearable
                    />
                    <div class="d-flex align-center ga-2">
                        <v-btn type="submit" color="primary" :loading="loading"
                            >Apply filters</v-btn
                        >
                        <v-btn variant="text" :disabled="loading" @click="reset">Reset</v-btn>
                    </div>
                </form>
            </v-card>

            <div class="report-totals mb-4" aria-live="polite">
                <v-card
                    v-for="column in moneyColumns"
                    :key="column.key"
                    class="pa-4"
                    variant="tonal"
                    color="primary"
                >
                    <div class="text-caption text-medium-emphasis">{{ column.title }}</div>
                    <v-skeleton-loader v-if="summaryLoading" type="text" />
                    <div v-else class="text-h6">{{ money(totals?.[column.key]) }}</div>
                </v-card>
            </div>
            <p class="text-caption text-medium-emphasis mb-3">
                Totals cover all matching records.
                <template v-if="report === 'progress'"
                    >Weekly periods start on Monday. Bet-date payouts use the winner record
                    date.</template
                >
            </p>
            <v-card>
                <div class="pa-4 d-flex ga-3 align-center">
                    <v-text-field
                        v-model="form.search"
                        label="Search report"
                        prepend-inner-icon="mdi-magnify"
                        clearable
                        maxlength="100"
                        hide-details="auto"
                        :error-messages="page.props.errors.search"
                        @update:model-value="search"
                        @keyup.enter="visit()"
                    />
                </div>
                <v-progress-linear
                    v-if="loading"
                    indeterminate
                    color="primary"
                    aria-label="Loading report"
                />
                <v-table density="compact" class="report-table">
                    <thead>
                        <tr>
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                :aria-sort="
                                    form.sort === column.key
                                        ? form.direction === 'asc'
                                            ? 'ascending'
                                            : 'descending'
                                        : 'none'
                                "
                            >
                                <button type="button" class="report-sort" @click="sort(column.key)">
                                    {{ column.title
                                    }}<v-icon
                                        v-if="form.sort === column.key"
                                        :icon="
                                            form.direction === 'asc'
                                                ? 'mdi-arrow-up'
                                                : 'mdi-arrow-down'
                                        "
                                        size="14"
                                    />
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in records.data" :key="row.id">
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                :class="{ 'text-right': column.format === 'money' }"
                            >
                                <Link
                                    v-if="
                                        column.key === 'game' && gameId(row) && can('Ending Games')
                                    "
                                    :href="showGame.url(gameId(row)!)"
                                    >{{ display(row[column.key], column.format) }}</Link
                                >
                                <span v-else>{{ display(row[column.key], column.format) }}</span>
                            </td>
                        </tr>
                        <tr v-if="!records.data.length">
                            <td
                                :colspan="columns.length"
                                class="text-center pa-8 text-medium-emphasis"
                            >
                                No records match these filters.
                            </td>
                        </tr>
                    </tbody>
                </v-table>
                <EndingPager :page="records" selectable :page-sizes="[10, 25, 50, 100]" />
            </v-card>
        </div>
    </div>
</template>

<style scoped>
.report-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 16px;
    align-items: start;
}
.report-totals {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px;
}
.report-table :deep(th),
.report-table :deep(td) {
    white-space: nowrap;
    padding: 12px 16px;
}
.report-sort {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-align: left;
    font: inherit;
    cursor: pointer;
}
.report-sort:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: 4px;
}
.report-table a {
    color: rgb(var(--v-theme-primary));
}
</style>
