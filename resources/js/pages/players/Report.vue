<script setup lang="ts">
import { show } from '@/actions/App/Http/Controllers/PlayerController';
import {
    index,
    exportMethod as exportReport,
} from '@/actions/App/Http/Controllers/PlayerReportController';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import PlayerShell from '../../components/players/PlayerShell.vue';
import PlayerStatus from '../../components/players/PlayerStatus.vue';
import type { PageResult } from '../../types/ending';
import { playerDate } from '../../types/players';

type ReportRow = Record<string, string | number | null> & { id: number; name: string | null };
type ReportKind = 'registered' | 'new' | 'active';
type Column = { key: string; title: string; format?: 'money' | 'status' | 'date' };
const props = defineProps<{
    report: ReportKind;
    title: string;
    players: PageResult<ReportRow>;
    filters: Record<string, string | number | null>;
    today: string;
    luckyFourReady: boolean;
}>();
const page = usePage();
const loading = ref(false);
const form = reactive({
    search: '',
    date_from: '',
    date_to: '',
    status: 'All',
    account_verified: 'All',
    sort: '',
    direction: 'desc',
});
watch(
    () => props.filters,
    (filters) => {
        for (const key of Object.keys(form) as (keyof typeof form)[]) {
            form[key] = String(filters[key] ?? '');
        }
    },
    { immediate: true },
);

const columns = computed<Column[]>(() => {
    const common: Column[] = [
        { key: 'id', title: 'Player ID' },
        { key: 'name', title: 'Name' },
        { key: 'mobile_number', title: 'Mobile Number' },
    ];
    if (props.report === 'active') {
        return [
            ...common,
            { key: 'city_municipality', title: 'City' },
            { key: 'age', title: 'Age' },
            { key: 'gender', title: 'Gender' },
            { key: 'total_bet_count', title: 'Total Bets' },
            { key: 'ending_amount', title: 'Happy Ending', format: 'money' },
            { key: 'lucky_four_amount', title: 'Lucky Four', format: 'money' },
            { key: 'total_bets', title: 'Total Bet Amount', format: 'money' },
            { key: 'total_payouts', title: 'Total Payouts', format: 'money' },
            { key: 'net_amount', title: 'Net Amount', format: 'money' },
        ];
    }
    return [
        ...common,
        { key: 'email_address', title: 'Email' },
        { key: 'account_verified', title: 'Verification Status', format: 'status' },
        { key: 'active_status', title: 'Account Status', format: 'status' },
        { key: 'created_at', title: 'Registered Date', format: 'date' },
        ...(props.report === 'new' ? [{ key: 'city_municipality', title: 'City' }] : []),
    ];
});
const periods = computed(() => [
    ...(props.report === 'registered' ? ['All dates'] : []),
    'Today',
    'This Week',
    'This Month',
    'This Year',
    'Custom',
]);
const period = computed(() => {
    if (!form.date_from && !form.date_to) return 'All dates';
    for (const label of ['Today', 'This Week', 'This Month', 'This Year']) {
        const [from, to] = datesFor(label);
        if (form.date_from === from && form.date_to === to) return label;
    }
    return 'Custom';
});
let timer: ReturnType<typeof setTimeout> | undefined;
function visit(targetPage = 1) {
    clearTimeout(timer);
    router.get(
        index.url(props.report),
        { ...form, per_page: props.players.per_page, page: targetPage },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
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
function datesFor(label: string): [string, string] {
    if (label === 'All dates') return ['', ''];
    const start = new Date(`${props.today}T00:00:00Z`);
    const end = new Date(start);
    if (label === 'This Week') {
        start.setUTCDate(start.getUTCDate() - ((start.getUTCDay() + 6) % 7));
        end.setTime(start.getTime());
        end.setUTCDate(end.getUTCDate() + 6);
    } else if (label === 'This Month') {
        start.setUTCDate(1);
        end.setUTCMonth(end.getUTCMonth() + 1, 0);
    } else if (label === 'This Year') {
        start.setUTCMonth(0, 1);
        end.setUTCMonth(11, 31);
    }
    return [start.toISOString().slice(0, 10), end.toISOString().slice(0, 10)];
}
function changePeriod(value: string) {
    if (value === 'Custom') return;
    [form.date_from, form.date_to] = datesFor(value);
    visit();
}
function reset() {
    Object.assign(form, {
        search: '',
        status: 'All',
        account_verified: 'All',
        direction: 'desc',
        sort: props.report === 'active' ? 'total_bets' : 'created_at',
    });
    changePeriod(props.report === 'registered' ? 'All dates' : 'Today');
}
function exportUrl(scope: 'all' | 'page') {
    return exportReport.url(props.report, {
        query: { ...props.filters, page: props.players.current_page, scope },
    });
}
function money(value: string | number | null) {
    return Number(value ?? 0).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
}
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <PlayerShell :title="title">
        <p class="text-body-2 text-medium-emphasis mb-4">
            <template v-if="report === 'active'"
                >Players with Ending bets in the selected period. Total bet amount includes Happy
                Ending and Lucky Four; payouts include released Ending cash prizes recorded in the
                period.</template
            >
            <template v-else-if="report === 'new'"
                >Players who registered during the selected period.</template
            >
            <template v-else
                >All registered players, with optional registration date and account
                filters.</template
            >
            Dates use Philippine time.
        </p>
        <v-alert v-if="!luckyFourReady" type="warning" variant="tonal" class="mb-4">
            Lucky Four history has not been verified yet. Betting totals may be incomplete.
        </v-alert>
        <v-card>
            <div class="players-toolbar flex-wrap">
                <div class="d-flex align-center ga-2">
                    <v-btn
                        icon="mdi-sync"
                        variant="text"
                        color="secondary"
                        aria-label="Refresh report"
                        :loading="loading"
                        @click="visit(players.current_page)"
                    />
                    <v-menu>
                        <template #activator="{ props: menuProps }">
                            <v-btn
                                v-bind="menuProps"
                                prepend-icon="mdi-file-delimited-outline"
                                variant="text"
                                :disabled="loading"
                                >Export CSV</v-btn
                            >
                        </template>
                        <v-list>
                            <v-list-item title="Export current page" :href="exportUrl('page')" />
                            <v-list-item
                                title="Export all matching players"
                                :href="exportUrl('all')"
                            />
                        </v-list>
                    </v-menu>
                    <v-chip size="small" variant="tonal"
                        >{{ players.total.toLocaleString() }} players</v-chip
                    >
                </div>
                <div class="players-search-controls">
                    <v-text-field
                        v-model="form.search"
                        :placeholder="
                            report === 'active'
                                ? 'Search name, mobile, city'
                                : 'Search name, mobile, email'
                        "
                        aria-label="Search players"
                        hide-details
                        clearable
                        maxlength="100"
                        @update:model-value="search"
                        @keyup.enter="visit()"
                    />
                </div>
            </div>
            <form class="player-report-filters" @submit.prevent="visit()">
                <v-select
                    :model-value="period"
                    :items="periods"
                    label="Period"
                    hide-details
                    @update:model-value="changePeriod"
                />
                <v-text-field
                    v-model="form.date_from"
                    label="Date From"
                    type="date"
                    :required="report !== 'registered'"
                    hide-details
                />
                <v-text-field
                    v-model="form.date_to"
                    label="Date To"
                    type="date"
                    :min="form.date_from || undefined"
                    :required="report !== 'registered'"
                    hide-details
                />
                <template v-if="report !== 'active'">
                    <v-select
                        v-model="form.account_verified"
                        :items="['All', 'Verified', 'Pending', 'Files Submitted', 'Rejected']"
                        label="Verification Status"
                        hide-details
                    />
                    <v-select
                        v-model="form.status"
                        :items="['All', 'Active', 'Inactive']"
                        label="Account Status"
                        hide-details
                    />
                </template>
                <div class="d-flex ga-2 align-center">
                    <v-btn type="submit" color="primary" :loading="loading">Apply</v-btn>
                    <v-btn variant="text" :disabled="loading" @click="reset">Reset</v-btn>
                </div>
            </form>
            <v-alert
                v-if="Object.keys(page.props.errors).length"
                type="error"
                variant="tonal"
                class="ma-4"
                >{{ Object.values(page.props.errors)[0] }}</v-alert
            >
            <v-progress-linear v-if="loading" indeterminate color="primary" height="2" />
            <v-divider />
            <v-table class="players-table" :aria-busy="loading">
                <thead>
                    <tr>
                        <th>Details</th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="{ 'text-right': column.format === 'money' }"
                            :aria-sort="
                                form.sort === column.key
                                    ? form.direction === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : undefined
                            "
                        >
                            <button class="players-sort" @click="sort(column.key)">
                                {{ column.title }}
                                <v-icon
                                    :icon="
                                        form.sort === column.key && form.direction === 'desc'
                                            ? 'mdi-arrow-down'
                                            : 'mdi-arrow-up'
                                    "
                                    size="14"
                                    :class="form.sort !== column.key ? 'players-sort-inactive' : ''"
                                />
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="player in players.data" :key="player.id">
                        <td>
                            <v-btn
                                :href="show.url(player.id)"
                                target="_blank"
                                rel="noopener"
                                icon="mdi-text-box-search-outline"
                                variant="text"
                                color="primary"
                                size="small"
                                :aria-label="`Details for ${player.name || player.id}`"
                            />
                        </td>
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            :class="{
                                'text-right': column.format === 'money',
                                'players-name': column.key === 'name',
                            }"
                        >
                            <PlayerStatus
                                v-if="column.format === 'status'"
                                :status="String(player[column.key] ?? '')"
                            />
                            <span
                                v-else-if="column.format === 'money'"
                                :class="
                                    column.key === 'net_amount' && Number(player[column.key]) < 0
                                        ? 'text-error'
                                        : ''
                                "
                                >{{ money(player[column.key]) }}</span
                            >
                            <template v-else-if="column.format === 'date'">{{
                                playerDate(player[column.key] as string | null)
                            }}</template>
                            <template v-else>{{ player[column.key] ?? '-' }}</template>
                        </td>
                    </tr>
                    <tr v-if="!players.data.length">
                        <td
                            :colspan="columns.length + 1"
                            class="text-center text-medium-emphasis py-8"
                        >
                            No players found for these filters.
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="players" selectable />
        </v-card>
    </PlayerShell>
</template>

<style scoped>
.player-report-filters {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 0 24px 20px;
}
.player-report-filters > .v-input {
    flex: 1 1 170px;
    min-width: 150px;
    max-width: 240px;
}
@media (max-width: 600px) {
    .player-report-filters > .v-input {
        max-width: none;
    }
}
</style>
