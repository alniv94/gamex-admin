<script setup lang="ts">
import {
    index,
    settlement,
    specialDetails,
    exportMethod,
} from '@/actions/App/Http/Controllers/InvestorController';
import EndingPager from '@/components/ending/EndingPager.vue';
import InvestorForm from '@/components/investors/InvestorForm.vue';
import InvestorHierarchy from '@/components/investors/InvestorHierarchy.vue';
import InvestorShell from '@/components/investors/InvestorShell.vue';
import InvestorSubAccounts from '@/components/investors/InvestorSubAccounts.vue';
import { usePermissions } from '@/composables/usePermissions';
import type { PageResult } from '@/types/ending';
import type { InvestorRow, Outlet } from '@/types/investors';
import { investorName, investorMoney } from '@/types/investors';
import { playerDate } from '@/types/players';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { VBtn } from 'vuetify/components';

const props = defineProps<{
    section: string;
    title: string;
    filters: Record<string, string | number | null>;
    records: PageResult<InvestorRow>;
    outlets: Outlet[];
    ready: boolean;
}>();
interface Column {
    title: string;
    key: string;
    numeric?: boolean;
}
const { can } = usePermissions();
const page = usePage();
const form = reactive({
    search: '',
    status: 'All',
    date_from: '',
    date_to: '',
    link_type: 'New',
    sort: '',
    direction: 'asc',
});
const draft = reactive({ status: 'All', date_from: '', date_to: '' });
const loading = ref(false);
const filterOpen = ref(false);
const editorOpen = ref(false);
const editing = ref<InvestorRow>();
const managed = ref<InvestorRow>();
const hierarchyAccount = ref<InvestorRow>();
const hierarchyKind = ref<'agents' | 'regular-players'>('agents');
let searchTimer: ReturnType<typeof setTimeout> | undefined;
const special = computed(() => props.section === 'special-commissions');
const referrals = computed(() => props.section === 'referral-links');
const master = computed(() => props.section === 'master-agents');
const commission = computed(() => props.section === 'commissions');
const payouts = computed(() => props.section === 'payouts');
const isList = computed(() => props.section === 'list');
const statuses = computed(() =>
    special.value ? ['All', 'Requested', 'Verified', 'Approved'] : ['All', 'Active', 'Inactive'],
);
watch(
    () => props.filters,
    (filters) => {
        for (const key of Object.keys(form) as (keyof typeof form)[])
            form[key] = String(filters[key] ?? '');
    },
    { immediate: true },
);
watch(
    () => props.section,
    () => {
        editorOpen.value = false;
        filterOpen.value = false;
        managed.value = undefined;
        hierarchyAccount.value = undefined;
    },
);
const headers = computed<Column[]>(() => {
    const action = { title: 'Action', key: '' };
    const name = { title: 'Name', key: 'name' };
    if (isList.value)
        return [
            action,
            { title: 'Territory', key: 'territory' },
            { title: 'Investor Name', key: 'name' },
            { title: 'Mobile Number', key: 'mobile_number' },
            { title: 'Base %', key: 'base_commission', numeric: true },
            { title: 'Commission %', key: 'commission', numeric: true },
            { title: 'Sub %', key: 'sub_commission', numeric: true },
            { title: 'Category', key: 'category' },
            { title: 'Wallet', key: 'wallet', numeric: true },
            { title: 'Link Type', key: 'link_type' },
            { title: 'Date Created', key: 'created_at' },
        ];
    if (payouts.value)
        return [
            action,
            name,
            { title: 'Payout', key: 'amount', numeric: true },
            { title: 'Covered Dates', key: 'min_date' },
        ];
    if (commission.value)
        return [
            action,
            name,
            { title: 'Status', key: 'status' },
            { title: 'Unpaid Commission', key: 'amount', numeric: true },
            { title: 'Bet Count', key: 'bet_count', numeric: true },
            { title: 'Bet Amount', key: 'bet_amount', numeric: true },
        ];
    if (master.value)
        return [
            name,
            { title: 'Total Account', key: 'total_accounts', numeric: true },
            { title: 'SM', key: 'sales_manager_count' },
            { title: 'RP', key: 'regular_player_count' },
            { title: 'Mobile Number', key: 'mobile_number' },
            { title: 'Commission', key: 'commission', numeric: true },
            { title: 'Sub Commission', key: 'sub_commission', numeric: true },
            { title: 'Wallet', key: 'wallet', numeric: true },
        ];
    if (referrals.value)
        return [name, { title: 'Link For', key: 'link_for' }, { title: 'Link', key: 'url' }];
    return [
        action,
        name,
        { title: 'Items', key: '', numeric: true },
        { title: 'Amount', key: 'amount', numeric: true },
        { title: 'Status', key: 'status' },
        { title: 'Process Date', key: '' },
    ];
});
function visit() {
    clearTimeout(searchTimer);
    router.get(
        index.url(props.section),
        { ...form, per_page: props.records.per_page },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
            onSuccess: () => (filterOpen.value = false),
        },
    );
}
function search() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(visit, 300);
}
function sort(key: string) {
    form.direction = form.sort === key && form.direction === 'asc' ? 'desc' : 'asc';
    form.sort = key;
    visit();
}
function openFilters() {
    Object.assign(draft, { status: form.status, date_from: form.date_from, date_to: form.date_to });
    filterOpen.value = true;
}
function applyFilters() {
    Object.assign(form, draft);
    visit();
}
function resetFilters() {
    Object.assign(draft, { status: 'All', date_from: '', date_to: '' });
    applyFilters();
}
function edit(row?: InvestorRow) {
    editing.value = row;
    editorOpen.value = true;
}
function openHierarchy(row: InvestorRow, kind: 'agents' | 'regular-players') {
    hierarchyAccount.value = row;
    hierarchyKind.value = kind;
}
function cash(value: string | number | undefined) {
    return (
        'Php ' +
        Number(value ?? 0).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })
    );
}
function coveredDate(value: string | null | undefined) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: '2-digit',
        year: '2-digit',
    }).format(new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z'));
}
onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
    <InvestorShell :title="title" :embedded="commission">
        <v-alert v-if="!ready" type="info" variant="tonal" class="mb-4"
            >Investor data is being prepared. Account changes and settlements will be available when
            it is ready.</v-alert
        >
        <v-alert
            v-if="Object.keys(page.props.errors).length && !editorOpen && !managed"
            type="error"
            variant="tonal"
            class="mb-4"
            role="alert"
            >{{ Object.values(page.props.errors)[0] }}</v-alert
        >
        <v-card class="investor-card" :class="{ 'investor-commission-card': commission }">
            <h1 v-if="commission" class="investor-commission-title">Commission</h1>
            <div class="investor-toolbar" :class="{ 'investor-referrals-toolbar': referrals }">
                <div class="d-flex align-center">
                    <v-btn
                        v-if="isList && can('Investors')"
                        icon="mdi-plus"
                        variant="text"
                        aria-label="New Investor"
                        title="New Investor"
                        :disabled="!ready"
                        @click="edit()"
                    />
                    <v-btn
                        v-if="isList"
                        icon="mdi-refresh"
                        variant="text"
                        aria-label="Refresh investors"
                        title="Refresh"
                        :loading="loading"
                        @click="visit"
                    />
                </div>
                <div class="investor-search-controls">
                    <v-btn
                        v-if="special"
                        :href="exportMethod.url(section, { query: { ...form } })"
                        icon="mdi-download"
                        variant="text"
                        aria-label="Export CSV"
                        title="Export CSV"
                    />
                    <v-select
                        v-if="referrals"
                        v-model="form.link_type"
                        :items="['Old', 'New']"
                        aria-label="Link type"
                        variant="outlined"
                        density="compact"
                        hide-details
                        class="investor-link-type"
                        @update:model-value="visit"
                    />
                    <v-text-field
                        v-model="form.search"
                        placeholder="Search"
                        aria-label="Search investors"
                        variant="outlined"
                        density="compact"
                        hide-details
                        clearable
                        class="investor-search"
                        @update:model-value="search"
                        @keyup.enter="visit"
                    />
                    <v-btn
                        v-if="!master && !referrals"
                        icon="mdi-filter-variant"
                        variant="text"
                        aria-label="Filter investors"
                        title="Filter"
                        :disabled="loading"
                        @click="openFilters"
                    />
                </div>
            </div>
            <v-progress-linear v-if="loading" indeterminate height="2" />
            <v-divider v-if="!commission" />
            <v-table
                class="players-table investor-table"
                :class="{ 'investor-referrals-table': referrals }"
                :aria-busy="loading"
            >
                <thead>
                    <tr>
                        <th
                            v-for="header in headers"
                            :key="header.title"
                            :class="{ 'text-right': header.numeric }"
                            :aria-sort="
                                header.key && form.sort === header.key
                                    ? form.direction === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : undefined
                            "
                        >
                            <button
                                v-if="header.key"
                                class="players-sort"
                                @click="sort(header.key)"
                            >
                                {{ header.title
                                }}<v-icon
                                    :icon="
                                        form.sort === header.key && form.direction === 'desc'
                                            ? 'mdi-arrow-down'
                                            : 'mdi-arrow-up'
                                    "
                                    size="12"
                                    class="ml-1"
                                    :class="{ 'investor-sort-active': form.sort === header.key }"
                                />
                            </button>
                            <template v-else>{{ header.title }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in records.data" :key="row.id">
                        <td v-if="!master && !referrals" class="investor-actions">
                            <template v-if="isList">
                                <v-btn
                                    icon="mdi-square-edit-outline"
                                    variant="text"
                                    color="primary"
                                    aria-label="Edit investor"
                                    title="Edit investor"
                                    :disabled="!ready"
                                    @click="edit(row)"
                                />
                                <v-btn
                                    icon="mdi-account-multiple"
                                    variant="text"
                                    color="primary"
                                    aria-label="Manage sub-accounts"
                                    title="Manage sub-accounts"
                                    @click="managed = row"
                                />
                            </template>
                            <Link
                                v-else-if="special"
                                :href="specialDetails.url(row.id)"
                                :as="VBtn"
                                icon="mdi-eye-outline"
                                color="primary"
                                variant="text"
                                aria-label="View special settlement"
                            />
                            <v-btn
                                v-else-if="
                                    commission &&
                                    (Number(row.amount) <= 0 || row.status !== 'Active')
                                "
                                icon="mdi-cash-check"
                                color="primary"
                                variant="tonal"
                                disabled
                                aria-label="Review settlement"
                            />
                            <Link
                                v-else-if="payouts || commission"
                                :href="
                                    settlement.url(
                                        { kind: section, investor: row.id },
                                        {
                                            query: {
                                                date_from: form.date_from,
                                                date_to: form.date_to,
                                            },
                                        },
                                    )
                                "
                                :as="VBtn"
                                :icon="payouts ? 'mdi-database-eye-outline' : 'mdi-cash-check'"
                                color="primary"
                                :variant="commission ? 'tonal' : 'text'"
                                aria-label="Review settlement"
                                title="Review settlement"
                            />
                        </td>
                        <td v-if="isList">{{ row.territory || '—' }}</td>
                        <td>{{ referrals ? row.name : investorName(row) }}</td>
                        <template v-if="isList">
                            <td>{{ row.mobile_number ? '+63' + row.mobile_number : '—' }}</td>
                            <td class="text-right">
                                {{ Number(row.base_commission).toFixed(2) }}%
                            </td>
                            <td class="text-right">{{ Number(row.commission).toFixed(2) }}%</td>
                            <td class="text-right">{{ Number(row.sub_commission).toFixed(2) }}%</td>
                            <td>
                                <v-chip
                                    size="x-small"
                                    :color="row.category === 'Regular' ? 'success' : 'primary'"
                                    variant="tonal"
                                    >{{ row.category }}</v-chip
                                >
                            </td>
                            <td class="text-right">{{ cash(row.wallet) }}</td>
                            <td>{{ row.link_type }}</td>
                            <td>{{ playerDate(row.created_at) }}</td>
                        </template>
                        <template v-else-if="payouts">
                            <td class="text-right">{{ cash(row.amount) }}</td>
                            <td>
                                {{ coveredDate(row.min_date) }} — {{ coveredDate(row.max_date) }}
                            </td>
                        </template>
                        <template v-else-if="commission">
                            <td>
                                <v-chip
                                    size="x-small"
                                    :color="row.status === 'Active' ? 'success' : 'error'"
                                    variant="tonal"
                                    >{{ row.status }}</v-chip
                                >
                            </td>
                            <td class="text-right">{{ cash(row.amount) }}</td>
                            <td class="text-right">{{ (row.bet_count ?? 0).toLocaleString() }}</td>
                            <td class="text-right">{{ cash(row.bet_amount) }}</td>
                        </template>
                        <template v-else-if="master">
                            <td class="text-right">
                                {{ (row.total_accounts ?? 0).toLocaleString() }}
                            </td>
                            <td>
                                <button
                                    v-if="row.sales_manager_count"
                                    class="investor-count-link"
                                    :aria-label="'View sales managers for ' + investorName(row)"
                                    @click="openHierarchy(row, 'agents')"
                                >
                                    View ({{ row.sales_manager_count }})</button
                                ><span v-else>—</span>
                            </td>
                            <td>
                                <button
                                    v-if="row.regular_player_count"
                                    class="investor-count-link"
                                    :aria-label="'View regular players for ' + investorName(row)"
                                    @click="openHierarchy(row, 'regular-players')"
                                >
                                    View ({{ row.regular_player_count }})</button
                                ><span v-else>—</span>
                            </td>
                            <td>{{ row.mobile_number ? '+63' + row.mobile_number : '—' }}</td>
                            <td class="text-right">{{ Number(row.commission).toFixed(2) }}%</td>
                            <td class="text-right">{{ Number(row.sub_commission).toFixed(2) }}%</td>
                            <td class="text-right">{{ investorMoney(row.wallet) }}</td>
                        </template>
                        <template v-else-if="referrals">
                            <td>{{ row.link_for }}</td>
                            <td>
                                <a
                                    v-if="row.url"
                                    :href="row.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="investor-referral-url"
                                    >{{ row.url }}</a
                                ><span v-else>Territory required</span>
                            </td>
                        </template>
                        <template v-else
                            ><td class="text-right">{{ row.items }}</td>
                            <td class="text-right">{{ cash(row.amount) }}</td>
                            <td>{{ row.status }}</td>
                            <td>{{ playerDate(row.process_date) }}</td></template
                        >
                    </tr>
                    <tr v-if="!records.data.length">
                        <td :colspan="headers.length" class="text-center pa-8">
                            No records match these filters.
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="records" selectable />
        </v-card>
        <v-dialog v-model="filterOpen" max-width="500">
            <v-form @submit.prevent="applyFilters">
                <v-card title="Filters">
                    <v-card-text>
                        <v-alert
                            v-if="Object.keys(page.props.errors).length"
                            type="error"
                            variant="tonal"
                            class="mb-4"
                            role="alert"
                            >{{ Object.values(page.props.errors)[0] }}</v-alert
                        >
                        <v-text-field
                            v-model="draft.date_from"
                            label="Date from"
                            type="date"
                            :error-messages="page.props.errors.date_from"
                        />
                        <v-text-field
                            v-model="draft.date_to"
                            label="Date to"
                            type="date"
                            :error-messages="page.props.errors.date_to"
                        />
                        <v-select
                            v-if="!payouts && !commission"
                            v-model="draft.status"
                            label="Status"
                            :items="statuses"
                        />
                    </v-card-text>
                    <v-card-actions
                        ><v-btn variant="text" @click="resetFilters">Reset</v-btn><v-spacer /><v-btn
                            @click="filterOpen = false"
                            >Cancel</v-btn
                        ><v-btn type="submit" color="primary" variant="flat" :loading="loading"
                            >Apply filters</v-btn
                        ></v-card-actions
                    >
                </v-card>
            </v-form>
        </v-dialog>
        <v-dialog v-model="editorOpen" max-width="820" scrollable>
            <InvestorForm
                v-if="editorOpen"
                :key="editing?.id ?? 'new'"
                :account="editing"
                :outlets="outlets"
                @saved="editorOpen = false"
                @cancel="editorOpen = false"
            />
        </v-dialog>
        <v-dialog
            :model-value="!!managed"
            max-width="980"
            scrollable
            @update:model-value="!$event && (managed = undefined)"
        >
            <InvestorSubAccounts
                v-if="managed"
                :key="managed.id"
                :investor="managed"
                @close="managed = undefined"
            />
        </v-dialog>
        <v-dialog
            :model-value="!!hierarchyAccount"
            max-width="1100"
            scrollable
            @update:model-value="!$event && (hierarchyAccount = undefined)"
        >
            <InvestorHierarchy
                v-if="hierarchyAccount"
                :key="hierarchyAccount.id + hierarchyKind"
                :investor="hierarchyAccount"
                :kind="hierarchyKind"
                @close="hierarchyAccount = undefined"
            />
        </v-dialog>
    </InvestorShell>
</template>

<style scoped>
.investor-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    min-height: 65px;
    padding: 14px 24px;
}
.investor-toolbar :deep(.v-btn--icon),
.investor-actions :deep(.v-btn--icon) {
    width: 32px;
    height: 32px;
}
.investor-toolbar :deep(.v-icon),
.investor-actions :deep(.v-icon) {
    font-size: 18px;
}
.investor-search-controls {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 16px;
}
.investor-search {
    width: 176px;
}
.investor-search :deep(input) {
    font-size: 12px;
    min-height: 36px;
}
.investor-link-type {
    width: 222px;
    flex: 0 0 222px;
}
.investor-referrals-table :deep(table) {
    table-layout: fixed;
}
.investor-referrals-table th:first-child {
    width: 28%;
}
.investor-referrals-table th:nth-child(2) {
    width: 22%;
}
.investor-referrals-table th:nth-child(3) {
    width: 50%;
}
.investor-referral-url {
    color: inherit;
    text-decoration: none;
}
.investor-referral-url:hover {
    text-decoration: underline;
}
.investor-table {
    font-size: 13px;
    --v-table-row-height: 44px;
    --v-table-header-height: 43px;
}
.investor-table th {
    font-size: 11px;
}
.investor-table :deep(.v-table__wrapper > table > tbody > tr > td) {
    height: 44px;
}
.investor-table :deep(.v-chip) {
    height: 22px;
    font-size: 11px;
    padding: 0 10px;
}
.investor-actions {
    width: 110px;
}
.investor-count-link {
    color: rgb(var(--v-theme-primary));
    background: transparent;
    border: 0;
    padding: 0;
    font: inherit;
    cursor: pointer;
}
.investor-count-link:hover {
    text-decoration: underline;
}
.players-sort :deep(.v-icon) {
    opacity: 0.55;
}
.players-sort :deep(.investor-sort-active) {
    opacity: 1;
    color: #172e4d;
}
.investor-commission-card {
    padding: 0 14px 14px;
}
.investor-commission-title {
    font-size: 18px;
    font-weight: 400;
    padding: 14px 0 0;
}
.investor-commission-card .investor-toolbar {
    padding-right: 0;
}
@media (max-width: 600px) {
    .investor-referrals-toolbar .investor-search-controls {
        flex: 1;
    }
    .investor-link-type {
        width: auto;
        flex: 1;
        min-width: 100px;
    }
    .investor-referrals-table :deep(table) {
        min-width: 960px;
    }
    .investor-toolbar {
        padding: 12px;
        gap: 8px;
    }
    .investor-search-controls {
        gap: 8px;
        min-width: 0;
    }
    .investor-search {
        width: 155px;
        max-width: 100%;
    }
    .investor-commission-card {
        padding-inline: 10px;
    }
}
</style>
