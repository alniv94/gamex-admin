<script setup lang="ts">
import {
    index,
    exportMethod as exportReport,
} from '@/actions/App/Http/Controllers/EndingReportController';
import { show as showGame } from '@/actions/App/Http/Controllers/EndingGameController';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import type { DataTableHeader, DataTableSortItem } from 'vuetify';
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
const headers = computed<DataTableHeader[]>(() =>
    props.columns.map((column): DataTableHeader => ({
        key: column.key,
        title: column.title,
        align: isNumeric(column) ? 'end' : 'start',
        sortable: true,
        nowrap: true,
        headerProps: {
            class: 'bg-grey-lighten-4 text-blue-grey-darken-2 text-body-small font-weight-medium',
        },
        cellProps: { class: 'text-body-medium text-no-wrap' },
    })),
);
const tableSort = computed<DataTableSortItem[]>(() => [
    {
        key: String(props.filters.sort ?? 'id'),
        order: props.filters.direction === 'asc' ? 'asc' : 'desc',
    },
]);
function isNumeric(column: Column) {
    return (
        column.format === 'money' ||
        ['bets', 'games', 'bettors', 'cards', 'winners', 'players', 'transactions'].includes(
            column.key,
        )
    );
}
function summaryIcon(key: string) {
    if (key === 'payout') return 'mdi-bank-transfer-out';
    if (key === 'amount') return 'mdi-cash-multiple';
    if (key === 'token') return 'mdi-wallet-outline';
    return 'mdi-chart-box-outline';
}
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
function updateTable(options: { page: number; itemsPerPage: number; sortBy: DataTableSortItem[] }) {
    const selectedSort = options.sortBy[0];
    if (!selectedSort) return;
    const direction = selectedSort.order === 'desc' ? 'desc' : 'asc';
    if (
        options.page === props.records.current_page &&
        options.itemsPerPage === Number(props.records.per_page) &&
        selectedSort.key === props.filters.sort &&
        direction === props.filters.direction
    ) {
        return;
    }
    form.sort = selectedSort.key;
    form.direction = direction;
    form.per_page = options.itemsPerPage;
    visit(options.page);
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
        <v-defaults-provider
            :defaults="{
                VBtn: { class: 'text-none font-weight-medium', rounded: 'lg' },
                VCard: { border: true, rounded: 'lg' },
                VFieldLabel: { class: 'text-blue-grey-darken-1 opacity-100' },
                VTextField: {
                    variant: 'outlined',
                    density: 'compact',
                    rounded: 'lg',
                    color: 'light-blue-darken-2',
                },
                VSelect: {
                    variant: 'outlined',
                    density: 'compact',
                    rounded: 'lg',
                    color: 'light-blue-darken-2',
                    hideDetails: true,
                },
            }"
        >
            <v-container
                fluid
                class="px-3 px-sm-6 py-5 text-blue-grey-darken-4"
                style="--v-font-body: Poppins, sans-serif; --v-font-heading: var(--v-font-body)"
            >
                <p class="text-body-small text-medium-emphasis mt-0 mb-2">Ending Reports</p>
                <v-row align="center" class="mb-1">
                    <v-col cols="12" md>
                        <div class="d-flex align-center flex-wrap ga-3">
                            <h1 class="text-headline-small font-weight-semibold ma-0">
                                {{ title }}
                            </h1>
                            <v-chip size="small" variant="outlined" color="blue-grey">
                                {{ records.total.toLocaleString() }}
                                {{ records.total === 1 ? 'record' : 'records' }}
                            </v-chip>
                        </div>
                    </v-col>
                    <v-col cols="12" md="auto">
                        <div class="d-flex flex-wrap ga-2">
                            <v-btn
                                prepend-icon="mdi-refresh"
                                variant="outlined"
                                color="blue-grey-darken-2"
                                class="flex-grow-1 flex-sm-grow-0"
                                :loading="loading"
                                @click="visit(records.current_page)"
                                >Refresh report</v-btn
                            >
                            <v-menu>
                                <template #activator="{ props: menuProps }">
                                    <v-btn
                                        v-bind="menuProps"
                                        prepend-icon="mdi-download-outline"
                                        append-icon="mdi-chevron-down"
                                        color="light-blue-darken-2"
                                        class="flex-grow-1 flex-sm-grow-0"
                                        :disabled="loading"
                                        >Export report</v-btn
                                    >
                                </template>
                                <v-list density="compact">
                                    <v-list-subheader>EXPORT REPORT</v-list-subheader>
                                    <v-list-item
                                        prepend-icon="mdi-file-delimited-outline"
                                        title="CSV · All matching records"
                                        subtitle="Opens in Excel"
                                        :href="exportUrl('all')"
                                    />
                                    <v-list-item
                                        prepend-icon="mdi-file-delimited-outline"
                                        title="CSV · Current page"
                                        :href="exportUrl('page')"
                                    />
                                    <v-list-item
                                        prepend-icon="mdi-file-pdf-box"
                                        title="PDF · Current page"
                                        :href="exportUrl('page', 'pdf')"
                                    />
                                </v-list>
                            </v-menu>
                        </div>
                    </v-col>
                </v-row>
                <p class="text-body-medium text-medium-emphasis mt-0 mb-6">
                    <template v-if="isAgent"
                        >Current master agent token balances and assigned players. Bet activity uses
                        the selected dates. Agent records come from the legacy system.</template
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
                </p>

                <v-card class="mb-5">
                    <v-card-item class="px-4 px-sm-6 pt-5 pb-0">
                        <template #prepend>
                            <v-avatar
                                icon="mdi-filter-variant"
                                color="light-blue-darken-2"
                                variant="tonal"
                                rounded="lg"
                                size="36"
                                class="mr-2"
                            />
                        </template>
                        <v-card-title tag="h2" class="text-title-small font-weight-semibold ma-0"
                            >Report filters</v-card-title
                        >
                        <v-card-subtitle class="text-body-small text-wrap">
                            Choose a date range and refine your results.
                        </v-card-subtitle>
                    </v-card-item>
                    <form @submit.prevent="visit()">
                        <v-card-text class="pa-4 pa-sm-6">
                            <v-row>
                                <v-col cols="12" sm="6" md="4" lg="3">
                                    <v-text-field
                                        v-model="form.date_from"
                                        type="date"
                                        label="Date from"
                                        :error-messages="page.props.errors.date_from"
                                        hide-details="auto"
                                        persistent-placeholder
                                        required
                                    />
                                </v-col>
                                <v-col cols="12" sm="6" md="4" lg="3">
                                    <v-text-field
                                        v-model="form.date_to"
                                        type="date"
                                        label="Date to"
                                        :min="String(form.date_from)"
                                        :error-messages="page.props.errors.date_to"
                                        hide-details="auto"
                                        persistent-placeholder
                                        required
                                    />
                                </v-col>
                                <v-col v-if="isGameReport" cols="12" sm="6" md="4" lg="3">
                                    <v-select
                                        v-model="form.league_id"
                                        label="League"
                                        :items="leagues"
                                        item-title="league"
                                        item-value="id"
                                        placeholder="All leagues"
                                        persistent-placeholder
                                        clearable
                                        hide-details
                                        @update:model-value="form.team_id = null"
                                    />
                                </v-col>
                                <v-col v-if="isGameReport" cols="12" sm="6" md="4" lg="3">
                                    <v-select
                                        v-model="form.team_id"
                                        label="Team"
                                        :items="teamsInLeague"
                                        item-title="team_name"
                                        item-value="id"
                                        placeholder="All teams"
                                        persistent-placeholder
                                        clearable
                                        hide-details
                                    />
                                </v-col>
                                <v-col v-if="isGameReport" cols="12" sm="6" md="4" lg="3">
                                    <v-select
                                        v-model="form.status"
                                        label="Game status"
                                        :items="['All', 'Not Started', 'Ongoing', 'Finished']"
                                        hide-details
                                    />
                                </v-col>
                                <v-col v-if="isGameReport" cols="12" sm="6" md="4" lg="3">
                                    <v-select
                                        v-model="form.filter_by"
                                        label="Date basis"
                                        :items="[
                                            { title: 'Scheduled date', value: 'scheduled' },
                                            {
                                                title:
                                                    report === 'progress'
                                                        ? 'Bet / payout date'
                                                        : 'Bet date',
                                                value: 'bet',
                                            },
                                        ]"
                                        hide-details
                                    />
                                </v-col>
                                <v-col
                                    v-if="
                                        isGameReport &&
                                        ['bets', 'game-card', 'cards'].includes(report)
                                    "
                                    cols="12"
                                    sm="6"
                                    md="4"
                                    lg="3"
                                >
                                    <v-select
                                        v-model="form.bet_type_id"
                                        label="Bet type"
                                        placeholder="All bet types"
                                        persistent-placeholder
                                        :items="[
                                            { title: 'Traditional', value: 1 },
                                            { title: 'Per Quarter', value: 2 },
                                        ]"
                                        clearable
                                        hide-details
                                    />
                                </v-col>
                                <v-col v-if="report === 'progress'" cols="12" sm="6" md="4" lg="3">
                                    <v-select
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
                                </v-col>
                                <v-col v-if="isCashier" cols="12" sm="6" md="4" lg="3">
                                    <v-select
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
                                </v-col>
                                <v-col
                                    v-if="
                                        ['bets', 'transactional', 'cash-in', 'cash-out'].includes(
                                            report,
                                        )
                                    "
                                    cols="12"
                                    sm="6"
                                    md="4"
                                    lg="3"
                                >
                                    <v-text-field
                                        v-model="form.player_id"
                                        type="number"
                                        min="1"
                                        label="Player ID"
                                        hide-details="auto"
                                        :error-messages="page.props.errors.player_id"
                                        clearable
                                    />
                                </v-col>
                                <v-col v-if="isGameReport" cols="12" sm="6" md="4" lg="3">
                                    <v-text-field
                                        v-model="form.game_id"
                                        type="number"
                                        min="1"
                                        label="Game ID"
                                        hide-details="auto"
                                        :error-messages="page.props.errors.game_id"
                                        clearable
                                    />
                                </v-col>
                                <v-col
                                    v-if="['bets', 'game-card'].includes(report)"
                                    cols="12"
                                    sm="6"
                                    md="4"
                                    lg="3"
                                >
                                    <v-text-field
                                        v-model="form.card_id"
                                        type="number"
                                        min="1"
                                        label="Card number"
                                        hide-details="auto"
                                        :error-messages="page.props.errors.card_id"
                                        clearable
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                        <v-divider />
                        <v-card-actions
                            class="d-flex flex-wrap justify-space-between ga-3 px-4 px-sm-6 py-3 bg-grey-lighten-5"
                        >
                            <span
                                class="d-inline-flex align-center ga-2 text-body-small text-medium-emphasis"
                            >
                                <v-icon icon="mdi-clock-outline" size="16" />
                                Philippine time (PHT)
                            </span>
                            <div class="d-flex flex-wrap justify-end flex-grow-1 ga-2">
                                <v-btn
                                    variant="text"
                                    color="blue-grey-darken-2"
                                    prepend-icon="mdi-filter-remove-outline"
                                    :disabled="loading"
                                    @click="reset"
                                    >Reset filters</v-btn
                                >
                                <v-btn
                                    type="submit"
                                    variant="flat"
                                    color="light-blue-darken-2"
                                    prepend-icon="mdi-filter-check-outline"
                                    :loading="loading"
                                    >Apply filters</v-btn
                                >
                            </div>
                        </v-card-actions>
                    </form>
                </v-card>

                <v-row aria-label="Report totals" aria-live="polite" :aria-busy="summaryLoading">
                    <v-col
                        v-for="column in moneyColumns"
                        :key="column.key"
                        cols="12"
                        sm="6"
                        :lg="moneyColumns.length <= 3 ? 12 / moneyColumns.length : 4"
                    >
                        <v-card class="h-100">
                            <v-card-text class="pa-4">
                                <div class="d-flex align-center justify-space-between ga-2">
                                    <span class="text-body-small text-medium-emphasis">{{
                                        column.title
                                    }}</span>
                                    <v-avatar
                                        :icon="summaryIcon(column.key)"
                                        :color="
                                            column.key === 'payout'
                                                ? 'teal-darken-1'
                                                : 'light-blue-darken-2'
                                        "
                                        variant="tonal"
                                        rounded="lg"
                                        size="32"
                                    />
                                </div>
                                <v-skeleton-loader v-if="summaryLoading" type="text" height="40" />
                                <p
                                    v-else
                                    class="text-headline-small font-weight-semibold text-break mt-2 mb-0"
                                    :class="{ 'text-error': Number(totals?.[column.key]) < 0 }"
                                >
                                    {{ money(totals?.[column.key]) }}
                                </p>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
                <p class="text-body-small text-medium-emphasis mt-3 mb-5">
                    <v-icon icon="mdi-information-outline" size="14" class="mr-1" />
                    Totals cover all matching records.
                    <template v-if="report === 'progress'">
                        Weekly periods start on Monday. Bet-date payouts use the winner record date.
                    </template>
                </p>

                <v-card>
                    <v-card-text class="px-4 px-sm-6 py-4">
                        <v-row align="center">
                            <v-col cols="12" sm="6" md="8">
                                <h2 class="text-title-small font-weight-semibold ma-0">
                                    {{ title }} report
                                </h2>
                                <p class="text-body-small text-medium-emphasis mt-1 mb-0">
                                    {{ records.total.toLocaleString() }} matching
                                    {{ records.total === 1 ? 'record' : 'records' }}
                                </p>
                            </v-col>
                            <v-col cols="12" sm="6" md="4">
                                <v-text-field
                                    v-model="form.search"
                                    placeholder="Search report…"
                                    aria-label="Search report"
                                    prepend-inner-icon="mdi-magnify"
                                    clearable
                                    maxlength="100"
                                    hide-details="auto"
                                    :error-messages="page.props.errors.search"
                                    @update:model-value="search"
                                    @keyup.enter="visit()"
                                />
                            </v-col>
                        </v-row>
                    </v-card-text>
                    <p
                        v-if="columns.length > 5"
                        class="d-flex d-lg-none align-center ga-2 text-body-small text-medium-emphasis px-4 pb-3"
                    >
                        <v-icon icon="mdi-swap-horizontal" size="16" /> Scroll to see more columns
                    </p>
                    <v-divider />
                    <v-data-table-server
                        :headers="headers"
                        :items="records.data"
                        :items-length="records.total"
                        :page="records.current_page"
                        :items-per-page="records.per_page"
                        :sort-by="tableSort"
                        :loading="loading"
                        :disable-sort="loading"
                        :hide-default-body="!records.data.length"
                        :height="records.data.length > 10 ? 560 : undefined"
                        :mobile="false"
                        :aria-label="`${title} report`"
                        :aria-busy="loading"
                        class="text-blue-grey-darken-4"
                        color="light-blue-darken-2"
                        striped="even"
                        fixed-header
                        hover
                        must-sort
                        @update:options="updateTable"
                    >
                        <template
                            v-for="column in columns"
                            :key="column.key"
                            #[`item.${column.key}`]="{ item }"
                        >
                            <Link
                                v-if="column.key === 'game' && gameId(item) && can('Ending Games')"
                                :href="showGame.url(gameId(item)!)"
                                class="text-light-blue-darken-2 text-decoration-none font-weight-medium"
                                >{{ display(item[column.key], column.format) }}</Link
                            >
                            <span
                                v-else
                                :class="{
                                    'font-weight-medium': column.format === 'money',
                                    'text-medium-emphasis': column.format === 'date',
                                    'text-error':
                                        column.format === 'money' && Number(item[column.key]) < 0,
                                }"
                                >{{ display(item[column.key], column.format) }}</span
                            >
                        </template>
                        <template #bottom>
                            <v-empty-state
                                v-if="!records.data.length"
                                :icon="loading ? 'mdi-dots-horizontal' : 'mdi-file-search-outline'"
                                :text="
                                    loading
                                        ? 'Your results will appear here shortly.'
                                        : 'Try a different date range or adjust your filters to find the records you need.'
                                "
                                color="blue-grey-lighten-2"
                                size="64"
                                text-width="380"
                                class="py-10 px-4"
                                role="status"
                            >
                                <template #title>
                                    <h3 class="text-title-medium font-weight-medium ma-0">
                                        {{ loading ? 'Loading report…' : 'No records found' }}
                                    </h3>
                                </template>
                                <template #actions>
                                    <v-btn
                                        v-if="!loading"
                                        variant="outlined"
                                        color="blue-grey-darken-2"
                                        prepend-icon="mdi-filter-remove-outline"
                                        @click="reset"
                                        >Reset filters</v-btn
                                    >
                                </template>
                            </v-empty-state>
                            <v-divider />
                            <v-data-table-footer
                                :items-per-page-options="[10, 25, 50, 100]"
                                :show-first-last-page="false"
                                items-per-page-text="Rows per page:"
                                class="bg-grey-lighten-5 pa-3 text-body-small"
                            />
                        </template>
                    </v-data-table-server>
                </v-card>
            </v-container>
        </v-defaults-provider>
    </div>
</template>
