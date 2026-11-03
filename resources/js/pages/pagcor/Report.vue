<script setup lang="ts">
import {
    index,
    exportMethod as exportReport,
} from '@/actions/App/Http/Controllers/PagcorReportController';
import { show as endingGame } from '@/actions/App/Http/Controllers/EndingGameController';
import { show as luckyGame } from '@/actions/App/Http/Controllers/LuckyFourController';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { usePermissions } from '@/composables/usePermissions';
import type { PageResult } from '@/types/ending';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import type { DataTableHeader, DataTableSortItem } from 'vuetify';

type Row = Record<string, string | number | null>;
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
}>();
const page = usePage();
const { can } = usePermissions();
const loading = ref(false);
const search = ref<string | null>('');
const form = reactive({
    date_from: '',
    date_to: '',
    league_id: null as number | null,
    team_id: null as number | null,
    status: 'All',
    period: 'daily',
    gaming_venue: null as string | null,
});
watch(
    () => props.filters,
    (filters) => {
        Object.assign(form, {
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
            league_id: filters.league_id ?? null,
            team_id: filters.team_id ?? null,
            status: filters.status,
            period: filters.period,
            gaming_venue: filters.gaming_venue ?? null,
        });
        search.value = String(filters.search ?? '');
    },
    { immediate: true },
);
const transactional = computed(() => props.report === 'transactional');
const progress = computed(() => props.report.endsWith('progress'));
const lucky = computed(() => props.report.startsWith('lucky-four'));
const submitted = computed(() => Boolean(Number(props.filters.submitted)));
const teamsInLeague = computed(() =>
    props.teams.filter((team) => !form.league_id || team.league_id === Number(form.league_id)),
);
watch(
    () => form.league_id,
    () => {
        if (form.team_id && !teamsInLeague.value.some((team) => team.id === Number(form.team_id)))
            form.team_id = null;
    },
);
const headers = computed<DataTableHeader[]>(() =>
    props.columns.map((column) => ({
        key: column.key,
        title: column.title,
        nowrap: true,
        sortable: true,
        align: column.format === 'money' || column.key === 'games' ? 'end' : 'start',
        headerProps: {
            class: 'bg-grey-lighten-4 text-blue-grey-darken-2 text-body-small font-weight-medium',
        },
        cellProps: { class: 'text-body-medium text-no-wrap' },
    })),
);
const sortBy = computed<DataTableSortItem[]>(() => [
    { key: String(props.filters.sort), order: props.filters.direction === 'desc' ? 'desc' : 'asc' },
]);
let timer: ReturnType<typeof setTimeout> | undefined;
function visit(changes: Record<string, string | number | null> = {}) {
    clearTimeout(timer);
    router.get(
        index.url(props.report),
        { ...props.filters, page: 1, ...changes },
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
function submit() {
    visit({ ...form, submitted: 1, search: '' });
}
function searchRows() {
    if (!submitted.value) return;
    clearTimeout(timer);
    timer = setTimeout(() => visit({ search: search.value ?? '' }), 350);
}
function updateTable(options: { page: number; itemsPerPage: number; sortBy: DataTableSortItem[] }) {
    if (!submitted.value || !options.sortBy[0] || loading.value) return;
    const sort = options.sortBy[0].key;
    const direction = options.sortBy[0].order === 'desc' ? 'desc' : 'asc';
    if (
        options.page === props.records.current_page &&
        options.itemsPerPage === props.records.per_page &&
        sort === props.filters.sort &&
        direction === props.filters.direction
    )
        return;
    visit({ page: options.page, per_page: options.itemsPerPage, sort, direction });
}
function exportUrl(scope: 'all' | 'page', format: 'csv' | 'pdf' = 'csv') {
    return exportReport.url(props.report, {
        query: { ...props.filters, page: props.records.current_page, scope, format },
    });
}
function inactive(row: Row) {
    return progress.value
        ? Number(row.games) === 0
        : !row[transactional.value ? 'transaction_id' : 'game_id'];
}
function money(value: string | number | null | undefined) {
    return Number(value ?? 0).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
}
function display(value: string | number | null | undefined, format: string) {
    if (value === null || value === undefined || value === '') return '—';
    if (format === 'money') return money(value);
    if (format === 'date')
        return new Date(`${String(value).replace(' ', 'T')}Z`).toLocaleString('en-PH', {
            timeZone: 'Asia/Manila',
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    return String(value);
}
function gameUrl(row: Row) {
    if (!row.game_id) return null;
    if (lucky.value && can('Lucky Four')) return luckyGame.url(Number(row.game_id));
    if (!lucky.value && can('Ending Games')) return endingGame.url(Number(row.game_id));
    return null;
}
function periodUrl(row: Row) {
    const period = String(row.period);
    const from = `${period}${period.length === 4 ? '-01-01' : period.length === 7 ? '-01' : ''}`;
    const end = new Date(`${from}T00:00:00Z`);
    if (props.filters.period === 'weekly') end.setUTCDate(end.getUTCDate() + 6);
    if (props.filters.period === 'monthly') end.setUTCMonth(end.getUTCMonth() + 1, 0);
    if (props.filters.period === 'yearly') end.setUTCFullYear(end.getUTCFullYear(), 11, 31);
    const dateTo = end.toISOString().slice(0, 10);
    return index.url(lucky.value ? 'lucky-four-game' : 'ending-game', {
        query: {
            submitted: 1,
            date_from: from < String(props.filters.date_from) ? props.filters.date_from : from,
            date_to: dateTo > String(props.filters.date_to) ? props.filters.date_to : dateTo,
        },
    });
}
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <section>
        <Head :title="`PAGCOR Reports · ${title}`" />
        <v-container fluid class="pa-4 pa-md-6">
            <div class="text-body-small text-medium-emphasis mb-1">PAGCOR Reports</div>
            <h1 class="text-headline-small font-weight-bold mb-5">{{ title }}</h1>
            <v-alert v-if="page.props.errors.report" type="error" variant="tonal" class="mb-4">{{
                page.props.errors.report
            }}</v-alert>
            <v-defaults-provider
                :defaults="{
                    VTextField: { variant: 'outlined', density: 'compact', rounded: 'sm' },
                    VSelect: { variant: 'outlined', density: 'compact', rounded: 'sm' },
                    VAutocomplete: { variant: 'outlined', density: 'compact', rounded: 'sm' },
                }"
            >
                <v-card rounded="lg" elevation="0" border class="mb-5">
                    <v-card-title class="text-title-medium py-3 px-4">{{
                        transactional
                            ? 'Select Transaction Date'
                            : progress
                              ? 'Select Schedule'
                              : 'Search Games'
                    }}</v-card-title>
                    <v-divider />
                    <form class="pa-4" @submit.prevent="submit">
                        <v-row>
                            <v-col v-if="transactional" cols="12" md="4">
                                <v-select
                                    v-model="form.gaming_venue"
                                    label="Gaming Venue"
                                    :items="[
                                        {
                                            title: 'Lascasas Bagac Bataan',
                                            value: 'lascasas_bagac_bataan',
                                        },
                                    ]"
                                    :error-messages="page.props.errors.gaming_venue"
                                    hide-details="auto"
                                />
                            </v-col>
                            <v-col cols="12" :md="transactional ? 4 : 6">
                                <v-text-field
                                    v-model="form.date_from"
                                    type="date"
                                    :label="
                                        transactional
                                            ? 'Transaction Date From (00:00:00)'
                                            : 'Schedule Date Start (00:00:00)'
                                    "
                                    :error-messages="page.props.errors.date_from"
                                    hide-details="auto"
                                    persistent-placeholder
                                    required
                                />
                            </v-col>
                            <v-col cols="12" :md="transactional ? 4 : 6">
                                <v-text-field
                                    v-model="form.date_to"
                                    type="date"
                                    :label="
                                        transactional
                                            ? 'Transaction Date To (23:59:59) (Optional)'
                                            : 'Schedule Date End (23:59:59) (Optional)'
                                    "
                                    :min="form.date_from"
                                    :error-messages="page.props.errors.date_to"
                                    hide-details="auto"
                                    persistent-placeholder
                                    clearable
                                />
                            </v-col>
                            <template v-if="!transactional && !progress">
                                <v-col cols="12" md="6"
                                    ><v-autocomplete
                                        v-model="form.league_id"
                                        :items="leagues"
                                        item-title="league"
                                        item-value="id"
                                        label="League (Optional)"
                                        :error-messages="page.props.errors.league_id"
                                        hide-details="auto"
                                        clearable
                                /></v-col>
                                <v-col cols="12" md="6"
                                    ><v-autocomplete
                                        v-model="form.team_id"
                                        :items="teamsInLeague"
                                        item-title="team_name"
                                        item-value="id"
                                        label="Team (Optional)"
                                        :error-messages="page.props.errors.team_id"
                                        hide-details="auto"
                                        clearable
                                /></v-col>
                                <v-col cols="12" md="6"
                                    ><v-select
                                        v-model="form.status"
                                        :items="['All', 'Not Started', 'Ongoing', 'Finished']"
                                        label="Game Status"
                                        :error-messages="page.props.errors.status"
                                        hide-details="auto"
                                /></v-col>
                            </template>
                            <v-col v-if="progress" cols="12" class="py-0">
                                <v-radio-group
                                    v-model="form.period"
                                    inline
                                    hide-details="auto"
                                    :error-messages="page.props.errors.period"
                                    aria-label="Report period"
                                >
                                    <v-radio
                                        v-for="period in ['daily', 'weekly', 'monthly', 'yearly']"
                                        :key="period"
                                        :label="period.charAt(0).toUpperCase() + period.slice(1)"
                                        :value="period"
                                        color="primary"
                                    />
                                </v-radio-group>
                            </v-col>
                        </v-row>
                        <div class="d-flex ga-2 mt-4">
                            <v-btn
                                type="submit"
                                color="primary"
                                variant="outlined"
                                :loading="loading"
                                >{{ progress || transactional ? 'Submit' : 'Search' }}</v-btn
                            >
                            <v-btn
                                v-if="submitted"
                                variant="text"
                                :disabled="loading"
                                @click="router.get(index.url(report))"
                                >Reset</v-btn
                            >
                        </div>
                    </form>
                </v-card>
                <v-card rounded="lg" elevation="0" border>
                    <AdminTableToolbar
                        v-model="search"
                        :title="transactional ? 'Transactions' : progress ? 'Report' : 'Games'"
                        :total="records.total"
                        :searchable="!progress"
                        :loading="loading"
                        :exports="
                            submitted && records.data.length
                                ? [
                                      { title: 'All matching rows (CSV)', href: exportUrl('all') },
                                      { title: 'Current page (CSV)', href: exportUrl('page') },
                                      {
                                          title: 'Current page (PDF)',
                                          href: exportUrl('page', 'pdf'),
                                      },
                                  ]
                                : []
                        "
                        @search="searchRows"
                        @refresh="visit()"
                    />
                    <v-data-table-server
                        :headers="headers"
                        :items="records.data"
                        :items-length="records.total"
                        :page="records.current_page"
                        :items-per-page="records.per_page"
                        :items-per-page-options="[5, 10, 25, 50, 100]"
                        :sort-by="sortBy"
                        :loading="loading"
                        :disable-sort="loading"
                        :mobile="false"
                        :aria-label="`${title} PAGCOR report`"
                        :aria-busy="loading"
                        :no-data-text="
                            submitted
                                ? 'No records match your search.'
                                : 'Select a date range to generate the report.'
                        "
                        items-per-page-text="Rows per page:"
                        class="admin-table"
                        must-sort
                        hover
                        @update:options="updateTable"
                    >
                        <template #item="{ item }">
                            <tr v-if="inactive(item)">
                                <td class="text-no-wrap">
                                    {{ progress ? item.period : item.day }}
                                </td>
                                <td :colspan="columns.length - 1" class="text-medium-emphasis">
                                    No Activity
                                </td>
                            </tr>
                            <tr v-else>
                                <td
                                    v-for="column in columns"
                                    :key="column.key"
                                    :class="[
                                        'text-no-wrap',
                                        {
                                            'text-end':
                                                column.format === 'money' || column.key === 'games',
                                            'text-error':
                                                column.format === 'money' &&
                                                Number(item[column.key]) < 0,
                                        },
                                    ]"
                                >
                                    <Link
                                        v-if="column.key === 'game' && gameUrl(item)"
                                        :href="gameUrl(item)!"
                                        class="text-primary text-decoration-none"
                                        >{{ item.game }}</Link
                                    >
                                    <Link
                                        v-else-if="
                                            column.key === 'period' && can('Pagcor Game Report')
                                        "
                                        :href="periodUrl(item)"
                                        class="text-primary text-decoration-none"
                                        >{{ item.period }}</Link
                                    >
                                    <span v-else>{{
                                        display(item[column.key], column.format)
                                    }}</span>
                                </td>
                            </tr>
                        </template>
                        <template #body.append>
                            <tr
                                v-if="submitted && records.data.length"
                                class="bg-grey-lighten-4 font-weight-bold"
                            >
                                <td
                                    v-for="(column, columnIndex) in columns"
                                    :key="column.key"
                                    :class="{
                                        'text-end text-no-wrap':
                                            column.format === 'money' || column.key === 'games',
                                    }"
                                >
                                    <span v-if="columnIndex === 0">TOTAL</span>
                                    <template
                                        v-else-if="
                                            column.format === 'money' || column.key === 'games'
                                        "
                                    >
                                        <v-skeleton-loader
                                            v-if="totals === undefined"
                                            type="text"
                                            width="100"
                                            class="bg-transparent"
                                        />
                                        <span v-else>{{
                                            column.format === 'money'
                                                ? money(totals[column.key])
                                                : totals[column.key]
                                        }}</span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                        <template #bottom>
                            <fieldset
                                :disabled="loading"
                                aria-label="Report pagination"
                                class="border-0 pa-0 ma-0"
                            >
                                <v-data-table-footer
                                    :items-per-page-options="[5, 10, 25, 50, 100]"
                                    :show-first-last-page="false"
                                    items-per-page-text="Rows per page:"
                                />
                            </fieldset>
                        </template>
                    </v-data-table-server>
                </v-card>
            </v-defaults-provider>
        </v-container>
    </section>
</template>
