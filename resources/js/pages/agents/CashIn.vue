<script setup lang="ts">
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import { useTableFilters } from '@/composables/useTableFilters';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { exportTableItems } from '@/composables/useTableExport';
import AdminDataTable from '@/components/AdminDataTable.vue';
import type { DataTableHeader } from 'vuetify';
import { cashins, fund, show } from '@/actions/App/Http/Controllers/AgentController';
import { evaluate as evaluatePlayer } from '@/actions/App/Http/Controllers/PlayerCashierController';
import AgentShell from '@/components/agents/AgentShell.vue';
import AgentSortHeader from '@/components/agents/AgentSortHeader.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import { usePermissions } from '@/composables/usePermissions';
import type { AgentCashin, AgentFilters, AgentRow } from '@/types/agents';
import { agentName } from '@/types/agents';
import type { PageResult } from '@/types/ending';
import { playerDate, playerMoney } from '@/types/players';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

const props = defineProps<{
    records: PageResult<AgentCashin>;
    filters: AgentFilters;
    total: string;
    agents: AgentRow[];
    ready: boolean;
    today: string;
}>();
const { can } = usePermissions();
const page = usePage();
const filters = reactive({ ...props.filters });
watch(
    () => props.filters,
    (value) => Object.assign(filters, value),
);
const busy = ref(false);
const filterError = ref('');
const manualOpen = ref(false);
const detail = ref<AgentCashin>();
const action = ref('');
const confirmation = ref(false);
const evaluation = useForm({ expected: '', remarks: '' });
const manual = useForm({
    agent_id: null as number | null,
    amount: '',
    remarks: '',
    request_key: '',
});
const agents = computed(() =>
    props.agents.map((agent) => ({ ...agent, name: `${agentName(agent)} (${agent.username})` })),
);
let timer: ReturnType<typeof setTimeout> | undefined;
function visit() {
    clearTimeout(timer);
    if (filters.date_from && filters.date_to && filters.date_to < filters.date_from) {
        filterError.value = 'To date must be on or after from date.';
        return;
    }
    filterError.value = '';
    router.get(
        cashins.url(),
        { ...filters, page: 1 },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (filterOpen.value = false),
        },
    );
}
function search() {
    clearTimeout(timer);
    timer = setTimeout(visit, 300);
}
function sort(key: string) {
    filters.direction = filters.sort === key && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = key;
    visit();
}
function openManual() {
    manual.reset();
    manual.clearErrors();
    manual.request_key = crypto.randomUUID();
    manualOpen.value = true;
}
function submitManual() {
    manual.post(fund.url(), {
        preserveScroll: true,
        onSuccess: () => {
            manualOpen.value = false;
        },
    });
}
function openDetails(record: AgentCashin) {
    detail.value = record;
    action.value = '';
    confirmation.value = false;
    evaluation.reset();
    evaluation.clearErrors();
}
function actions(record: AgentCashin) {
    if (!record.player_id || !can('Cash In')) return [];
    if (record.status === 'Pending' && can('Cashin Verify')) return ['verify', 'reject'];
    if (record.status === 'Verified')
        return [
            ...(can('Cash In Approve') ? ['approve'] : []),
            ...(can('Cash In Dispprove') ? ['disapprove'] : []),
        ];
    return [];
}
function submitEvaluation() {
    if (!detail.value || !action.value) return;
    evaluation.expected = detail.value.status;
    const url = evaluatePlayer.url({
        kind: 'cashin',
        cashier: detail.value.id,
        action: action.value,
    });
    evaluation.post(url, { preserveScroll: true, onSuccess: () => (detail.value = undefined) });
}
onBeforeUnmount(() => clearTimeout(timer));
const manualError = computed(() => (manual.errors as Record<string, string>).action);
const evaluationError = computed(() => (evaluation.errors as Record<string, string>).action);

const dateOptions = [
    { label: 'Today', value: 'today' },
    { label: 'Last 7 days', value: 'week' },
    { label: 'This month', value: 'month' },
    { label: 'All dates', value: 'all' },
] as const;
function weekStart() {
    const date = new Date(`${props.today}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() - 6);
    return date.toISOString().slice(0, 10);
}
const datePreset = computed(() => {
    if (!draft.date_from && !draft.date_to) return 'all';
    if (draft.date_to !== props.today) return 'custom';
    if (draft.date_from === props.today) return 'today';
    if (draft.date_from === weekStart()) return 'week';
    if (draft.date_from === `${props.today.slice(0, 7)}-01`) return 'month';
    return 'custom';
});
function setDateRange(value: 'today' | 'week' | 'month' | 'all') {
    filters.date_from =
        value === 'all'
            ? ''
            : value === 'week'
              ? weekStart()
              : value === 'month'
                ? `${props.today.slice(0, 7)}-01`
                : props.today;
    filters.date_to = value === 'all' ? '' : props.today;
    visit();
}
function resetFilters() {
    Object.assign(filters, {
        agent_id: null,
        status: 'All',
        search: '',
        date_from: props.today,
        date_to: props.today,
        sort: 'created_at',
        direction: 'desc',
        per_page: props.records.per_page,
    });
    visit();
}
const rangeLabel = computed(() => {
    const { date_from: from, date_to: to } = props.filters;
    if (!from && !to) return 'All dates';
    if (from === to) return playerDate(from, true);
    if (!from) return `Through ${playerDate(to, true)}`;
    if (!to) return `From ${playerDate(from, true)}`;
    return `${playerDate(from, true)} to ${playerDate(to, true)}`;
});
const dateBasis = computed(() =>
    ['Approved', 'Disapproved', 'Rejected'].includes(draft.status)
        ? 'Evaluated date'
        : 'Created date',
);
const hasDateFilter = computed(() => Boolean(props.filters.date_from || props.filters.date_to));
const hasOtherFilters = computed(() =>
    Boolean(props.filters.agent_id || props.filters.search || props.filters.status !== 'All'),
);
const selectedAgent = computed(() => props.agents.find((agent) => agent.id === manual.agent_id));
const canFund = computed(() =>
    Boolean(manual.agent_id && Number(manual.amount) > 0 && manual.remarks.trim()),
);
const fieldDefaults = {
    VSelect: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VAutocomplete: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VTextarea: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
};
function statusColor(status: string) {
    return status === 'Approved'
        ? 'success'
        : status === 'Pending'
          ? 'warning'
          : status === 'Verified'
            ? 'info'
            : ['Disapproved', 'Rejected'].includes(status)
              ? 'error'
              : 'grey';
}
function actionLabel(value: string) {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

const tableHeaders: DataTableHeader<AgentCashin>[] = [
    { title: 'Agent', key: 'first_name', value: agentName, sortable: true },
    { title: 'Player', key: 'player_name', sortable: true },
    { title: 'Payment', key: 'payment_method', sortable: true },
    { title: 'Amount', key: 'amount', sortable: true },
    { title: 'Status', key: 'status', sortable: true },
    { title: 'Created at', key: 'created_at', sortable: true },
    { title: 'Evaluated at', key: 'evaluated_at', sortable: true },
    { title: 'Actions', key: 'actions', sortable: false },
];

const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => [
        'search',
        'status',
        {
            key: 'agent_id',
            options: props.agents.map((agent) => ({ title: agentName(agent), value: agent.id })),
        },
        'date_range',
    ],
    (changes) => {
        Object.assign(filters, changes);
        visit();
    },
    resetFilters,
);
const filterOpen = ref(false);
const draft = reactive({ ...filters });
function openFilters() {
    Object.assign(draft, props.filters);
    filterError.value = '';
    filterOpen.value = true;
}
function applyFilters() {
    if (draft.date_from && draft.date_to && draft.date_to < draft.date_from) {
        filterError.value = 'To date must be on or after from date.';
        return;
    }
    Object.assign(filters, draft);
    visit();
}
function setDraftDateRange(value: 'today' | 'week' | 'month' | 'all') {
    draft.date_from =
        value === 'all'
            ? ''
            : value === 'week'
              ? weekStart()
              : value === 'month'
                ? props.today.slice(0, 7) + '-01'
                : props.today;
    draft.date_to = value === 'all' ? '' : props.today;
}
</script>

<template>
    <AgentShell
        title="Cash In"
        subtitle="Review cash-in requests associated with agents."
        :ready="ready"
    >
        <template #actions>
            <v-btn
                v-if="can('Cash In Manual')"
                prepend-icon="mdi-wallet-plus-outline"
                color="#007faa"
                height="42"
                :disabled="!ready"
                @click="openManual"
                >Manual cash in</v-btn
            >
        </template>
        <v-defaults-provider :defaults="fieldDefaults">
            <v-card class="agents-panel" :aria-busy="busy">
                <AdminTableToolbar
                    :filters-open="filterOpen"
                    filterable
                    @filter="openFilters"
                    :active-filters="activeFilters"
                    @remove-filter="removeFilter"
                    @reset-filters="resetTableFilters"
                    :total="records.total"
                    v-model="filters.search"
                    title="Cash-in requests"
                    search-label="Search cash-ins"
                    refresh-label="Refresh cash-ins"
                    :exports="[
                        {
                            title: 'Current page (CSV)',
                            action: () =>
                                exportTableItems('agents-cashin.csv', records.data, tableHeaders),
                        },
                    ]"
                    @refresh="visit"
                    @search="search"
                    :loading="busy"
                >
                </AdminTableToolbar>

                <div class="cashin-summary">
                    <div class="cashin-total">
                        <span class="cashin-total-icon"
                            ><v-icon size="24">mdi-cash-multiple</v-icon></span
                        >
                        <div>
                            <div class="cashin-eyebrow">Total cash in</div>
                            <div class="cashin-total-value">
                                <span>PHP</span> {{ playerMoney(total) }}
                            </div>
                        </div>
                    </div>
                    <div class="cashin-count">
                        <strong>{{ records.total.toLocaleString() }}</strong
                        ><span>{{ records.total === 1 ? 'transaction' : 'transactions' }}</span>
                    </div>
                    <div class="cashin-range">
                        <v-icon size="17">mdi-calendar-range-outline</v-icon>
                        <div>
                            {{ rangeLabel
                            }}<span
                                >Filtered results ·
                                {{
                                    props.filters.status === 'All'
                                        ? 'All statuses'
                                        : props.filters.status
                                }}</span
                            >
                        </div>
                    </div>
                </div>
                <div class="cashin-progress">
                    <v-progress-linear v-if="busy" indeterminate color="#007faa" height="2" />
                </div>
                <AdminDataTable
                    v-if="records.data.length"
                    :headers="tableHeaders"
                    :items="records.data"
                    server
                    :toolbar="false"
                    title="Cash-in requests"
                >
                    <template #headers>
                        <tr>
                            <AgentSortHeader
                                label="Agent"
                                column="name"
                                :sort="filters.sort"
                                :direction="filters.direction"
                                :disabled="busy"
                                @sort="sort"
                            />
                            <th scope="col">Player</th>
                            <th scope="col">Payment</th>
                            <AgentSortHeader
                                label="Amount"
                                column="amount"
                                numeric
                                :sort="filters.sort"
                                :direction="filters.direction"
                                :disabled="busy"
                                @sort="sort"
                            />
                            <AgentSortHeader
                                label="Status"
                                column="status"
                                :sort="filters.sort"
                                :direction="filters.direction"
                                :disabled="busy"
                                @sort="sort"
                            />
                            <AgentSortHeader
                                label="Created"
                                column="created_at"
                                :sort="filters.sort"
                                :direction="filters.direction"
                                :disabled="busy"
                                @sort="sort"
                            />
                            <AgentSortHeader
                                label="Evaluated"
                                column="evaluated_at"
                                :sort="filters.sort"
                                :direction="filters.direction"
                                :disabled="busy"
                                @sort="sort"
                            />
                            <th scope="col">Details</th>
                        </tr>
                    </template>
                    <template #item="{ item: record }"
                        ><tr :key="record.id">
                            <td class="cashin-agent-cell">
                                <Link :href="show(record.agent_id)">{{ agentName(record) }}</Link
                                ><span class="cashin-cell-secondary"
                                    >{{ record.agent_type }} agent</span
                                >
                            </td>
                            <td class="cashin-player-cell">
                                {{ record.player_name || 'Identity pending' }}
                            </td>
                            <td>
                                <span>{{ record.payment_method || record.type }}</span
                                ><span
                                    class="cashin-cell-secondary cashin-reference"
                                    :title="record.reference_number || undefined"
                                    >{{ record.reference_number || `Cash-in #${record.id}` }}</span
                                >
                            </td>
                            <td class="cashin-amount">
                                <span>PHP</span> {{ playerMoney(record.amount) }}
                            </td>
                            <td>
                                <v-chip
                                    :color="statusColor(record.status)"
                                    size="small"
                                    variant="tonal"
                                    >{{ record.status }}</v-chip
                                >
                            </td>
                            <td class="cashin-date-cell">{{ playerDate(record.created_at) }}</td>
                            <td class="cashin-date-cell">{{ playerDate(record.evaluated_at) }}</td>
                            <td>
                                <v-btn
                                    icon="mdi-arrow-top-right"
                                    variant="text"
                                    size="small"
                                    color="#007faa"
                                    :disabled="busy"
                                    :aria-label="`View cash-in ${record.id}`"
                                    title="View transaction details"
                                    @click="openDetails(record)"
                                />
                            </td></tr
                    ></template>
                </AdminDataTable>
                <div v-else class="cashin-empty" role="status" aria-live="polite">
                    <v-progress-circular v-if="busy" indeterminate color="#007faa" size="36" />
                    <template v-else>
                        <div class="cashin-empty-icon">
                            <v-icon size="30">mdi-magnify</v-icon>
                        </div>
                        <h2>
                            {{
                                hasOtherFilters
                                    ? 'No matching cash-ins'
                                    : hasDateFilter
                                      ? 'No cash-ins in this date range'
                                      : 'No cash-ins yet'
                            }}
                        </h2>
                        <p>
                            {{
                                hasDateFilter || hasOtherFilters
                                    ? 'Try a wider date range or reset your filters to find a transaction.'
                                    : 'Cash-in requests associated with agents will appear here.'
                            }}
                        </p>
                        <div class="cashin-empty-actions">
                            <v-btn
                                v-if="hasDateFilter"
                                prepend-icon="mdi-calendar-search"
                                variant="outlined"
                                color="#007faa"
                                @click="setDateRange('all')"
                                >View all dates</v-btn
                            >
                            <v-btn v-if="hasOtherFilters" variant="text" @click="resetFilters"
                                >Reset filters</v-btn
                            >
                        </div>
                    </template>
                    <p v-if="busy">Loading cash-ins…</p>
                </div>
                <div class="cashin-footer">
                    <EndingPager :page="records" selectable class="cashin-pager" />
                </div>
            </v-card>
            <v-dialog
                v-model="manualOpen"
                max-width="560"
                :persistent="manual.processing"
                scrollable
            >
                <v-card class="cashin-dialog">
                    <div class="cashin-dialog-header">
                        <div class="cashin-dialog-icon">
                            <v-icon>mdi-wallet-plus-outline</v-icon>
                        </div>
                        <div>
                            <h2>Manual cash in</h2>
                            <p>Add funds directly to an agent’s balance.</p>
                        </div>
                        <v-spacer /><v-btn
                            icon="mdi-close"
                            variant="text"
                            size="small"
                            aria-label="Close funding form"
                            :disabled="manual.processing"
                            @click="manualOpen = false"
                        />
                    </div>
                    <v-card-text class="cashin-dialog-body">
                        <v-alert v-if="manualError" type="error" variant="tonal" class="mb-5">{{
                            manualError
                        }}</v-alert>
                        <form
                            id="agent-funding"
                            class="cashin-funding-form"
                            @submit.prevent="submitManual"
                        >
                            <v-autocomplete
                                v-model="manual.agent_id"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                :items="agents.filter((agent) => agent.is_active === 'Active')"
                                item-title="name"
                                item-value="id"
                                label="Select agent"
                                :error-messages="manual.errors.agent_id"
                                :disabled="manual.processing"
                            />
                            <div v-if="selectedAgent" class="cashin-wallet-balance">
                                <span>Current agent wallet</span
                                ><strong>PHP {{ playerMoney(selectedAgent.wallet) }}</strong>
                            </div>
                            <v-text-field
                                v-model="manual.amount"
                                label="Amount to add"
                                placeholder="0.00"
                                persistent-placeholder
                                prefix="PHP"
                                type="number"
                                min="0.01"
                                max="99999999.99"
                                step="0.01"
                                inputmode="decimal"
                                :error-messages="manual.errors.amount"
                                :disabled="manual.processing"
                                required
                            />
                            <v-textarea
                                v-model="manual.remarks"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                label="Reason or payment reference"
                                placeholder="Include the payment reference or reason for this funding."
                                :error-messages="manual.errors.remarks"
                                rows="3"
                                no-resize
                                :disabled="manual.processing"
                                required
                            />
                            <div class="cashin-funding-preview">
                                <div>
                                    <span>Amount to credit</span
                                    ><strong>PHP {{ playerMoney(manual.amount) }}</strong>
                                </div>
                                <p>
                                    <v-icon size="16">mdi-information-outline</v-icon> Funds are
                                    added to the agent wallet immediately after confirmation.
                                </p>
                            </div>
                        </form>
                    </v-card-text>
                    <v-card-actions class="cashin-dialog-footer"
                        ><v-btn
                            variant="text"
                            :disabled="manual.processing"
                            @click="manualOpen = false"
                            >Cancel</v-btn
                        ><v-spacer /><v-btn
                            type="submit"
                            form="agent-funding"
                            color="#007faa"
                            variant="flat"
                            prepend-icon="mdi-check"
                            height="42"
                            :disabled="!canFund"
                            :loading="manual.processing"
                            >Confirm funding</v-btn
                        ></v-card-actions
                    >
                </v-card>
            </v-dialog>
            <v-dialog
                :model-value="!!detail"
                max-width="680"
                :persistent="evaluation.processing"
                scrollable
                @update:model-value="
                    (value) => {
                        if (!value) detail = undefined;
                    }
                "
            >
                <v-card v-if="detail" class="cashin-dialog">
                    <div class="cashin-dialog-header">
                        <div class="cashin-dialog-icon">
                            <v-icon>mdi-receipt-text-outline</v-icon>
                        </div>
                        <div>
                            <h2>Cash-in #{{ detail.id }}</h2>
                            <p>Cash-in request</p>
                        </div>
                        <v-spacer /><v-btn
                            icon="mdi-close"
                            variant="text"
                            size="small"
                            aria-label="Close cash-in details"
                            :disabled="evaluation.processing"
                            @click="detail = undefined"
                        />
                    </div>
                    <v-card-text class="cashin-dialog-body">
                        <v-alert v-if="evaluationError" type="error" variant="tonal" class="mb-5">{{
                            evaluationError
                        }}</v-alert>
                        <div class="cashin-detail-amount">
                            <div>
                                <span>Transaction amount</span
                                ><strong>PHP {{ playerMoney(detail.amount) }}</strong>
                            </div>
                            <v-chip :color="statusColor(detail.status)" variant="tonal">{{
                                detail.status
                            }}</v-chip>
                        </div>
                        <dl class="cashin-detail-grid">
                            <div>
                                <dt>Agent</dt>
                                <dd>
                                    {{ agentName(detail)
                                    }}<span>{{ detail.agent_type }} agent</span>
                                </dd>
                            </div>
                            <div>
                                <dt>Player</dt>
                                <dd>{{ detail.player_name || 'Identity pending' }}</dd>
                            </div>
                            <div>
                                <dt>Payment method</dt>
                                <dd>{{ detail.payment_method || detail.type }}</dd>
                            </div>
                            <div>
                                <dt>Reference number</dt>
                                <dd>{{ detail.reference_number || '—' }}</dd>
                            </div>
                            <div>
                                <dt>Receiver account</dt>
                                <dd>{{ detail.receiver_account || '—' }}</dd>
                            </div>
                            <div>
                                <dt>Created</dt>
                                <dd>{{ playerDate(detail.created_at) }}</dd>
                            </div>
                            <div>
                                <dt>Evaluated by</dt>
                                <dd>{{ detail.evaluated_by || '—' }}</dd>
                            </div>
                            <div>
                                <dt>Evaluated</dt>
                                <dd>{{ playerDate(detail.evaluated_at) }}</dd>
                            </div>
                        </dl>
                        <v-btn
                            v-if="detail.receipt_url"
                            :href="detail.receipt_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            prepend-icon="mdi-image-outline"
                            append-icon="mdi-open-in-new"
                            color="#007faa"
                            variant="tonal"
                            class="mt-4"
                            >View receipt</v-btn
                        >
                        <div v-if="detail.notes || detail.remarks" class="cashin-detail-notes">
                            <div v-if="detail.notes">
                                <h3>Notes</h3>
                                <p>{{ detail.notes }}</p>
                            </div>
                            <div v-if="detail.remarks">
                                <h3>Remarks</h3>
                                <p>{{ detail.remarks }}</p>
                            </div>
                        </div>
                        <v-alert v-if="!detail.player_id" type="info" variant="tonal" class="mt-5"
                            >This player’s identity needs review before this deposit can be
                            processed.</v-alert
                        >
                        <div v-if="ready && actions(detail).length" class="cashin-review">
                            <h3>Review transaction</h3>
                            <v-select
                                v-model="action"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                :items="
                                    actions(detail).map((value) => ({
                                        title: actionLabel(value),
                                        value,
                                    }))
                                "
                                label="Choose an action"
                                class="mt-4"
                                :disabled="evaluation.processing"
                                @update:model-value="confirmation = false"
                            />
                            <v-textarea
                                v-if="action"
                                v-model="evaluation.remarks"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                :label="
                                    ['reject', 'disapprove'].includes(action)
                                        ? 'Reason (required)'
                                        : 'Remarks (optional)'
                                "
                                rows="2"
                                class="mt-4"
                                :disabled="evaluation.processing"
                                :error-messages="evaluation.errors.remarks"
                            />
                            <v-checkbox
                                v-if="action"
                                v-model="confirmation"
                                :disabled="evaluation.processing"
                                :label="
                                    action === 'approve'
                                        ? `Credit PHP ${playerMoney(detail.amount)} to this player wallet`
                                        : `Confirm ${action} for this request`
                                "
                                hide-details
                                density="compact"
                                class="mt-3"
                            />
                        </div>
                    </v-card-text>
                    <v-card-actions class="cashin-dialog-footer"
                        ><v-btn :disabled="evaluation.processing" @click="detail = undefined"
                            >Close</v-btn
                        ><v-spacer /><v-btn
                            v-if="action"
                            :color="['reject', 'disapprove'].includes(action) ? 'error' : '#007faa'"
                            variant="flat"
                            height="42"
                            :disabled="!confirmation"
                            :loading="evaluation.processing"
                            @click="submitEvaluation"
                            >Confirm {{ action }}</v-btn
                        ></v-card-actions
                    >
                </v-card>
            </v-dialog>
        </v-defaults-provider>
        <AdminFilterDialog
            v-model="filterOpen"
            :loading="busy"
            @apply="applyFilters"
            @reset="resetTableFilters"
        >
            <v-autocomplete
                v-model="draft.agent_id"
                :items="agents"
                item-title="name"
                item-value="id"
                label="Agent"
                placeholder="All agents"
                clearable
                :error-messages="page.props.errors.agent_id"
            />
            <v-select
                v-model="draft.status"
                :items="['All', 'Pending', 'Verified', 'Rejected', 'Approved', 'Disapproved']"
                label="Status"
            />
            <v-row dense
                ><v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.date_from"
                        type="date"
                        label="From date"
                        :error-messages="page.props.errors.date_from" /></v-col
                ><v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.date_to"
                        type="date"
                        label="To date"
                        :min="draft.date_from || undefined"
                        :error-messages="filterError || page.props.errors.date_to" /></v-col
            ></v-row>
            <div class="d-flex flex-wrap ga-2" aria-label="Quick date ranges">
                <v-btn
                    v-for="option in dateOptions"
                    :key="option.value"
                    size="small"
                    :variant="datePreset === option.value ? 'tonal' : 'text'"
                    :aria-pressed="datePreset === option.value"
                    @click="setDraftDateRange(option.value)"
                    >{{ option.label }}</v-btn
                >
            </div>
            <p class="text-caption text-medium-emphasis">{{ dateBasis }} ? Philippine time</p>
        </AdminFilterDialog></AgentShell
    >
</template>

<style scoped>
.cashin-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 20px 24px;
}
.cashin-list-title {
    font-size: 14px;
    font-weight: 600;
    color: #34445a;
}
.cashin-search {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 340px;
    max-width: 100%;
}
.cashin-search .v-input {
    min-width: 0;
}

.cashin-filters {
    padding: 20px 24px 14px;
    background: #fafbfd;
    border-block: 1px solid #e8edf3;
}
.cashin-filter-grid {
    display: grid;
    grid-template-columns:
        minmax(200px, 1.5fr) minmax(140px, 1fr) repeat(2, minmax(155px, 1fr))
        auto;
    align-items: start;
    gap: 12px;
}
.cashin-filter-grid .v-input {
    min-width: 0;
}
.cashin-filter-actions {
    display: flex;
    gap: 2px;
}
.cashin-date-options {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}
.cashin-presets {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}
.cashin-date-hint {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    color: #64748b;
}
.cashin-summary {
    display: flex;
    align-items: center;
    gap: 32px;
    padding: 24px;
}
.cashin-total {
    display: flex;
    align-items: center;
    gap: 14px;
}
.cashin-total-icon {
    display: grid;
    place-items: center;
    width: 48px;
    height: 48px;
    background: #edf8fc;
    border-radius: 12px;
    color: #007faa;
}
.cashin-eyebrow {
    color: #6b7b8e;
    font-size: 12px;
    margin-bottom: 3px;
}
.cashin-total-value {
    font-size: 27px;
    font-weight: 600;
    letter-spacing: -0.5px;
    line-height: 1.4;
    color: #172b42;
    font-variant-numeric: tabular-nums;
}
.cashin-total-value > span {
    font-size: 14px;
    font-weight: 400;
    color: #64748b;
    letter-spacing: 0;
}
.cashin-count {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding-left: 28px;
    border-left: 1px solid #e4eaf1;
}
.cashin-count strong {
    font-size: 23px;
    line-height: 1.4;
    font-weight: 600;
    color: #243449;
}
.cashin-count > span {
    color: #64748b;
    font-size: 12px;
}
.cashin-range {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #526579;
}
.cashin-range span {
    display: block;
    color: #64748b;
    font-size: 11px;
    margin-top: 3px;
}
.cashin-progress {
    height: 2px;
}
.cashin-agent-cell {
    min-width: 170px;
}
.cashin-player-cell {
    min-width: 160px;
    max-width: 230px;
}
.cashin-cell-secondary {
    display: block;
    font-size: 11px;
    color: #64748b;
    margin-top: 4px;
}
.cashin-reference {
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.cashin-amount {
    white-space: nowrap;
    text-align: right;
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    color: #243449;
}
.cashin-amount > span {
    font-weight: 400;
    color: #64748b;
    font-size: 11px;
}
.cashin-date-cell {
    min-width: 135px;
    max-width: 165px;
    font-size: 12px;
    line-height: 1.7;
    color: #64748b;
}
.cashin-empty {
    min-height: 280px;
    padding: 32px 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    text-align: center;
}
.cashin-empty-icon {
    display: grid;
    place-items: center;
    width: 64px;
    height: 64px;
    color: #7e9caf;
    background: #f0f6fa;
    border-radius: 50%;
    margin-bottom: 18px;
}
.cashin-empty h2 {
    font-size: 17px;
    color: #34445a;
    margin: 0 0 8px;
}
.cashin-empty p {
    max-width: 400px;
    font-size: 13px;
    color: #64748b;
    line-height: 1.7;
    margin-bottom: 18px;
}
.cashin-empty-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
}
.cashin-footer {
    border-top: 1px solid #edf1f5;
    padding-inline: 8px;
}
.cashin-dialog {
    color: #243449;
    border-radius: 12px !important;
}
.cashin-dialog :deep(.v-btn) {
    text-transform: none;
    letter-spacing: 0;
    font-size: 13px;
    font-weight: 500;
}
.cashin-dialog-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 22px 24px;
    border-bottom: 1px solid #e8edf3;
}
.cashin-dialog-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    background: #edf8fc;
    color: #007faa;
    border-radius: 10px;
}
.cashin-dialog-header h2 {
    font-size: 18px;
    line-height: 1.5;
}
.cashin-dialog-header p {
    font-size: 12px;
    color: #64748b;
    margin: 3px 0 0;
}
.cashin-dialog-body {
    padding: 24px !important;
}
.cashin-funding-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.cashin-wallet-balance {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-size: 12px;
    padding: 12px 14px;
    background: #f6f9fc;
    border-radius: 8px;
    margin-top: -8px;
    color: #64748b;
}
.cashin-wallet-balance strong {
    font-weight: 500;
    color: #34445a;
    font-variant-numeric: tabular-nums;
}
.cashin-funding-preview {
    border: 1px solid #dcecf4;
    border-radius: 8px;
    background: #f5fbfe;
    padding: 16px;
}
.cashin-funding-preview > div {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-size: 12px;
}
.cashin-funding-preview strong {
    font-size: 20px;
    color: #007faa;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.cashin-funding-preview p {
    margin: 12px 0 0;
    font-size: 12px;
    line-height: 1.7;
    color: #6b7b8e;
}
.cashin-dialog-footer {
    border-top: 1px solid #e8edf3;
    padding: 16px 24px !important;
    background: #fafbfd;
}
.cashin-detail-amount {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 24px;
}
.cashin-detail-amount > div {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.cashin-detail-amount > div > span {
    font-size: 12px;
    color: #64748b;
}
.cashin-detail-amount strong {
    font-size: 28px;
    font-weight: 600;
    letter-spacing: -0.4px;
}
.cashin-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}
.cashin-detail-grid dt {
    font-size: 11px;
    color: #64748b;
    margin-bottom: 4px;
}
.cashin-detail-grid dd {
    margin: 0;
    font-size: 13px;
    overflow-wrap: anywhere;
}
.cashin-detail-grid dd > span {
    display: block;
    color: #64748b;
    font-size: 11px;
    margin-top: 3px;
}
.cashin-detail-notes {
    margin-top: 24px;
    border-radius: 8px;
    background: #f6f9fc;
    padding: 16px;
    display: grid;
    gap: 16px;
}
.cashin-detail-notes h3,
.cashin-review h3 {
    font-size: 13px;
    font-weight: 500;
}
.cashin-detail-notes p {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    color: #64748b;
    font-size: 12px;
    margin-top: 4px;
}
.cashin-review {
    border-top: 1px solid #e8edf3;
    margin-top: 24px;
    padding-top: 22px;
}
@media (max-width: 1280px) {
    .cashin-filter-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    .cashin-filter-actions {
        grid-column: 1 / -1;
    }
}
@media (max-width: 960px) {
    .cashin-toolbar {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        padding-inline: 16px;
    }
    .cashin-search {
        width: 100%;
        padding-bottom: 8px;
    }

    .cashin-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .cashin-summary {
        gap: 20px;
        flex-wrap: wrap;
    }
    .cashin-range {
        margin-left: 0;
        flex-basis: 100%;
    }
}
@media (max-width: 600px) {
    .cashin-footer :deep(.cashin-pager) {
        flex-wrap: nowrap !important;
        gap: 3px !important;
        padding: 12px 8px !important;
    }
    .cashin-footer :deep(.cashin-pager > span) {
        font-size: 11px !important;
        margin-right: 0 !important;
        white-space: nowrap;
    }
    .cashin-footer :deep(.ending-page-size) {
        flex: 0 0 44px;
        min-width: 44px;
    }
    .cashin-footer :deep(.cashin-pager .v-btn) {
        width: 28px !important;
        height: 28px !important;
    }
    .cashin-filters {
        padding: 18px 16px 12px;
    }
    .cashin-agent-filter,
    .cashin-status-filter {
        grid-column: 1 / -1;
    }
    .cashin-date-options {
        gap: 12px;
    }
    .cashin-presets {
        gap: 0;
    }
    .cashin-presets :deep(.v-btn) {
        padding-inline: 10px;
        font-size: 11px;
    }
    .cashin-filter-actions .v-btn:first-child {
        flex: 1;
    }
    .cashin-summary {
        padding: 20px 16px;
        gap: 16px;
    }
    .cashin-total {
        gap: 10px;
    }
    .cashin-total-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
    }
    .cashin-total-value {
        font-size: 23px;
    }
    .cashin-count {
        padding-left: 16px;
    }
    .cashin-count strong {
        font-size: 20px;
    }
    .cashin-empty {
        min-height: 260px;
    }
    .cashin-empty h2 {
        font-size: 15px;
    }
    .cashin-dialog-header {
        padding: 18px 16px;
        gap: 10px;
    }
    .cashin-dialog-header h2 {
        font-size: 16px;
    }
    .cashin-dialog-icon {
        display: none;
    }
    .cashin-dialog-body {
        padding: 20px 16px !important;
    }
    .cashin-dialog-footer {
        padding: 14px 16px !important;
    }
    .cashin-detail-amount strong {
        font-size: 23px;
    }
    .cashin-funding-preview > div {
        align-items: start;
        flex-direction: column;
    }
}
</style>
