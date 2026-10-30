<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import {
    evaluate,
    exportMethod as exportCashier,
    index,
    manual,
    players as findPlayers,
} from '@/actions/App/Http/Controllers/PlayerCashierController';
import { show } from '@/actions/App/Http/Controllers/PlayerController';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import PlayerShell from '../../components/players/PlayerShell.vue';
import PlayerStatus from '../../components/players/PlayerStatus.vue';
import { useAdminApi } from '../../composables/useAdminApi';
import type { AdminPageProps } from '../../types';
import type { CashierPlayer, CashierRecord } from '../../types/cashier';
import type { PageResult } from '../../types/ending';
import { playerDate, playerMoney } from '../../types/players';

const props = defineProps<{
    kind: 'cashin' | 'cashout';
    records: PageResult<CashierRecord>;
    filters: Record<string, string | null>;
    today: string;
}>();
const page = usePage<AdminPageProps>();
const { can } = usePermissions();
const api = useAdminApi();
const title = computed(() => (props.kind === 'cashin' ? 'Cash In' : 'Cash Out'));
const canManual = computed(() => can(props.kind === 'cashin' ? 'cashin.manual' : 'cashout.manual'));
const loading = ref(false);
const form = reactive({
    search: '',
    filter: 'Pending',
    date_from: '',
    date_to: '',
    sort: 'created_at',
    direction: 'desc',
});
watch(
    () => props.filters,
    (filters) => {
        for (const key of Object.keys(form) as (keyof typeof form)[])
            form[key] =
                filters[key] ??
                (key === 'filter'
                    ? 'Pending'
                    : key === 'sort'
                      ? 'created_at'
                      : key === 'direction'
                        ? 'desc'
                        : '');
    },
    { immediate: true },
);
const filterOpen = ref(false);
const draft = reactive({ filter: 'Pending', date_from: '', date_to: '' });
const filterError = ref('');
let searchTimer: ReturnType<typeof setTimeout> | undefined;
function visit(targetPage = 1) {
    clearTimeout(searchTimer);
    router.get(
        index.url(props.kind),
        { ...form, per_page: props.records.per_page, page: targetPage },
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
    searchTimer = setTimeout(() => visit(), 350);
}
function sort(key: string) {
    form.direction = form.sort === key && form.direction === 'asc' ? 'desc' : 'asc';
    form.sort = key;
    visit();
}
function openFilters() {
    Object.assign(draft, { filter: form.filter, date_from: form.date_from, date_to: form.date_to });
    filterError.value = '';
    filterOpen.value = true;
}
function applyFilters() {
    if (draft.date_from && draft.date_to && draft.date_to < draft.date_from) {
        filterError.value = 'Date To must be on or after Date From.';
        return;
    }
    Object.assign(form, draft);
    visit();
}
const headers = computed(() => [
    { title: 'Actions', key: '' },
    { title: 'Player Name', key: 'player_name' },
    { title: 'Mobile Number', key: 'mobile_number' },
    ...(props.kind === 'cashout' ? [{ title: 'Wallet Token', key: 'wallet' }] : []),
    { title: 'Bank', key: 'bank' },
    ...(props.kind === 'cashout'
        ? [
              { title: 'Account Name', key: '' },
              { title: 'Account Number', key: '' },
          ]
        : [{ title: 'Reference Number', key: '' }]),
    { title: 'Token', key: 'amount' },
    ...(props.kind === 'cashin' ? [{ title: 'Type', key: 'type' }] : []),
    { title: 'Remarks', key: 'remarks' },
    { title: 'Status', key: 'status' },
    { title: 'Requested Date', key: 'created_at' },
]);
const selected = ref<CashierRecord | null>(null);
const decision = useForm({ expected: '', remarks: '' });
type Decision = 'verify' | 'reject' | 'approve' | 'disapprove';
const confirmAction = ref<Decision | null>(null);
const actions = computed(() => {
    if (!selected.value?.player_id) return [];
    const options: { action: Decision; label: string; permission: string }[] =
        selected.value.status === 'Pending'
            ? [
                  {
                      action: 'verify',
                      label: 'Verify',
                      permission: props.kind === 'cashin' ? 'cashin.verify' : 'cashout.verify',
                  },
                  {
                      action: 'reject',
                      label: 'Reject',
                      permission: props.kind === 'cashin' ? 'cashin.verify' : 'cashout.verify',
                  },
              ]
            : selected.value.status === 'Verified'
              ? [
                    {
                        action: 'approve',
                        label: 'Approve',
                        permission: props.kind === 'cashin' ? 'cashin.approve' : 'cashout.approve',
                    },
                    {
                        action: 'disapprove',
                        label: 'Disapprove',
                        permission:
                            props.kind === 'cashin' ? 'cashin.disapprove' : 'cashout.disapprove',
                    },
                ]
              : [];
    return options.filter((option) => can(option.permission));
});
function review(row: CashierRecord) {
    selected.value = row;
    decision.resetAndClearErrors();
    decision.expected = row.status;
    confirmAction.value = null;
}
function submitDecision() {
    if (!selected.value || !confirmAction.value || decision.processing) return;
    decision.submit(
        evaluate({ kind: props.kind, cashier: selected.value.id, action: confirmAction.value }),
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = null;
                confirmAction.value = null;
            },
        },
    );
}
const manualOpen = ref(false);
const manualForm = useForm({
    player_id: null as number | null,
    amount: '',
    remarks: '',
    approval_password: '',
    request_key: '',
});
const playerOptions = ref<CashierPlayer[]>([]);
const playerLoading = ref(false);
const playerSearchError = ref('');
const chosenPlayer = computed(() =>
    playerOptions.value.find((player) => player.id === manualForm.player_id),
);
let playerTimer: ReturnType<typeof setTimeout> | undefined;
let playerSearchVersion = 0;
function searchPlayers(value: string) {
    clearTimeout(playerTimer);
    const version = ++playerSearchVersion;
    playerSearchError.value = '';
    playerLoading.value = false;
    if (!value || value.length < 3) return;
    const current = chosenPlayer.value;
    if (current && value === `${current.name} (${current.mobile_number})`) return;
    playerTimer = setTimeout(async () => {
        playerLoading.value = true;
        try {
            const rows = await api<CashierPlayer[]>(
                findPlayers.url(props.kind, { query: { search: value } }),
                {},
                'GET',
            );
            if (version === playerSearchVersion) playerOptions.value = rows;
        } catch (error) {
            if (version === playerSearchVersion)
                playerSearchError.value =
                    error instanceof Error ? error.message : 'Unable to find players.';
        } finally {
            if (version === playerSearchVersion) playerLoading.value = false;
        }
    }, 350);
}
function openManual() {
    manualForm.resetAndClearErrors();
    manualForm.request_key = crypto.randomUUID();
    playerOptions.value = [];
    playerSearchError.value = '';
    manualOpen.value = true;
}
function submitManual() {
    manualForm.submit(manual(props.kind), {
        preserveScroll: true,
        onSuccess: () => {
            manualOpen.value = false;
            manualForm.resetAndClearErrors();
        },
        onFinish: () => manualForm.reset('approval_password'),
    });
}
watch(manualOpen, (open) => {
    if (!open) {
        manualForm.reset('approval_password');
        ++playerSearchVersion;
        clearTimeout(playerTimer);
    }
});
watch(
    () => props.kind,
    () => {
        selected.value = null;
        confirmAction.value = null;
        manualOpen.value = false;
        filterOpen.value = false;
        clearTimeout(searchTimer);
    },
);
onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    clearTimeout(playerTimer);
    ++playerSearchVersion;
});
</script>

<template>
    <PlayerShell :title="title">
        <v-card>
            <div class="players-toolbar">
                <div class="d-flex align-center">
                    <v-btn
                        v-if="canManual"
                        icon="mdi-cash-plus"
                        variant="text"
                        color="primary"
                        :aria-label="`Manual ${title}`"
                        :title="`Manual ${title}`"
                        @click="openManual"
                    />
                    <v-btn
                        icon="mdi-cached"
                        variant="text"
                        color="secondary"
                        :aria-label="`Refresh ${title}`"
                        title="Refresh"
                        :loading="loading"
                        @click="visit(records.current_page)"
                    />
                    <v-btn
                        :href="exportCashier.url(kind, { query: { ...form } })"
                        icon="mdi-file-delimited-outline"
                        variant="text"
                        color="secondary"
                        :aria-label="`Export ${title}`"
                        title="Export CSV"
                    />
                </div>
                <div class="players-search-controls">
                    <v-text-field
                        v-model="form.search"
                        placeholder="Search"
                        :aria-label="`Search ${title}`"
                        prepend-inner-icon="mdi-magnify"
                        clearable
                        maxlength="100"
                        hide-details
                        @update:model-value="search"
                        @keyup.enter="visit()"
                    />
                    <v-btn
                        icon="mdi-filter-variant"
                        variant="text"
                        color="secondary"
                        :aria-label="`Filter ${title}`"
                        title="Filter"
                        :disabled="loading"
                        @click="openFilters"
                    />
                </div>
            </div>
            <v-progress-linear v-if="loading" indeterminate color="primary" height="2" />
            <v-divider />
            <v-alert
                v-if="Object.keys(page.props.errors).length && !selected && !manualOpen"
                type="error"
                variant="tonal"
                class="ma-4"
                role="alert"
                >{{ Object.values(page.props.errors)[0] }}</v-alert
            >
            <v-table class="players-table" :aria-busy="loading">
                <thead>
                    <tr>
                        <th
                            v-for="header in headers"
                            :key="header.title"
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
                                {{ header.title }}
                                <v-icon
                                    :icon="
                                        form.sort === header.key && form.direction === 'desc'
                                            ? 'mdi-arrow-down'
                                            : 'mdi-arrow-up'
                                    "
                                    size="14"
                                    :class="form.sort !== header.key ? 'players-sort-inactive' : ''"
                                />
                            </button>
                            <template v-else>{{ header.title }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in records.data" :key="row.id">
                        <td>
                            <v-btn
                                icon="mdi-account-details"
                                variant="text"
                                color="primary"
                                size="small"
                                :aria-label="`Review ${title} request ${row.id}`"
                                title="Details"
                                @click="review(row)"
                            />
                        </td>
                        <td>{{ row.player_name || 'Unlinked player' }}</td>
                        <td>{{ row.mobile_number || '-' }}</td>
                        <td v-if="kind === 'cashout'">
                            {{ row.wallet === null ? '-' : playerMoney(row.wallet) }}
                        </td>
                        <td>{{ row.bank || '-' }}</td>
                        <template v-if="kind === 'cashout'"
                            ><td>{{ row.account_name || '-' }}</td>
                            <td>{{ row.account_number || '-' }}</td></template
                        >
                        <td v-else>{{ row.reference_number || '-' }}</td>
                        <td>{{ playerMoney(row.amount) }}</td>
                        <td v-if="kind === 'cashin'">{{ row.type }}</td>
                        <td>{{ row.remarks || '' }}</td>
                        <td><PlayerStatus :status="row.status" /></td>
                        <td>{{ playerDate(row.created_at) }}</td>
                    </tr>
                    <tr v-if="!records.data.length">
                        <td :colspan="headers.length" class="text-center text-medium-emphasis py-8">
                            No data available
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="records" :page-sizes="[5, 10, 15, 20, 25, 50, 100]" selectable />
        </v-card>
        <v-dialog v-model="filterOpen" max-width="420" :persistent="loading" class="players-dialog">
            <v-card class="pa-6"
                ><h2 class="mb-4">Filter {{ title }}</h2>
                <form @submit.prevent="applyFilters">
                    <v-select
                        v-model="draft.filter"
                        :items="[
                            'All',
                            'Pending',
                            'Verified',
                            'Rejected',
                            'Approved',
                            'Disapproved',
                        ]"
                        label="Status"
                        class="mb-4"
                    />
                    <p class="text-caption mb-4">
                        {{
                            ['All', 'Pending'].includes(draft.filter)
                                ? 'Filter by requested date.'
                                : 'Filter by evaluated date.'
                        }}
                    </p>
                    <v-text-field
                        v-model="draft.date_from"
                        type="date"
                        label="Date From"
                        :max="draft.date_to || today"
                        class="mb-4"
                    />
                    <v-text-field
                        v-model="draft.date_to"
                        type="date"
                        label="Date To"
                        :min="draft.date_from || undefined"
                        :max="today"
                        :error-messages="filterError"
                        class="mb-4"
                    />
                    <div class="d-flex justify-end ga-2">
                        <v-btn
                            variant="text"
                            :disabled="loading"
                            @click="
                                Object.assign(draft, {
                                    filter: 'Pending',
                                    date_from: '',
                                    date_to: '',
                                });
                                applyFilters();
                            "
                            >Reset</v-btn
                        >
                        <v-btn variant="text" :disabled="loading" @click="filterOpen = false"
                            >Cancel</v-btn
                        >
                        <v-btn type="submit" color="primary" :loading="loading">Filter</v-btn>
                    </div>
                </form>
            </v-card>
        </v-dialog>
        <v-dialog
            :model-value="!!selected"
            max-width="620"
            :persistent="decision.processing"
            class="players-dialog"
            @update:model-value="
                (open: boolean) => {
                    if (!open) selected = null;
                }
            "
        >
            <v-card v-if="selected" class="pa-6">
                <h2 class="mb-4">{{ title }} Request #{{ selected.id }}</h2>
                <a
                    v-if="selected.receipt_url"
                    :href="selected.receipt_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mb-4"
                >
                    <img
                        :src="selected.receipt_url"
                        alt="Cash in receipt"
                        referrerpolicy="no-referrer"
                        style="max-width: 100%; max-height: 360px; object-fit: contain"
                    />
                </a>
                <v-table
                    ><tbody>
                        <tr>
                            <td>Player</td>
                            <td>
                                <Link
                                    v-if="selected.player_id && can('players.view')"
                                    :href="show(selected.player_id)"
                                    >{{ selected.player_name }}</Link
                                ><template v-else>{{
                                    selected.player_name || 'Unlinked player'
                                }}</template>
                            </td>
                        </tr>
                        <tr>
                            <td>Mobile Number</td>
                            <td>{{ selected.mobile_number || '-' }}</td>
                        </tr>
                        <tr>
                            <td>Token</td>
                            <td>{{ playerMoney(selected.amount) }}</td>
                        </tr>
                        <tr>
                            <td>Wallet Token</td>
                            <td>
                                {{ selected.wallet === null ? '-' : playerMoney(selected.wallet) }}
                            </td>
                        </tr>
                        <tr>
                            <td>Bank</td>
                            <td>{{ selected.bank || '-' }}</td>
                        </tr>
                        <tr v-if="kind === 'cashout'">
                            <td>Account Name</td>
                            <td>{{ selected.account_name || '-' }}</td>
                        </tr>
                        <tr>
                            <td>Account Number</td>
                            <td>{{ selected.account_number || '-' }}</td>
                        </tr>
                        <tr v-if="kind === 'cashin'">
                            <td>Receiver Account</td>
                            <td>{{ selected.receiver_account || '-' }}</td>
                        </tr>
                        <tr v-if="kind === 'cashin'">
                            <td>Reference Number</td>
                            <td>{{ selected.reference_number || '-' }}</td>
                        </tr>
                        <tr>
                            <td>Type</td>
                            <td>{{ selected.type }}</td>
                        </tr>
                        <tr>
                            <td>Status</td>
                            <td><PlayerStatus :status="selected.status" /></td>
                        </tr>
                        <tr>
                            <td>Requested Date</td>
                            <td>{{ playerDate(selected.created_at) }}</td>
                        </tr>
                        <tr>
                            <td>Evaluated By</td>
                            <td>{{ selected.evaluated_by || '-' }}</td>
                        </tr>
                        <tr>
                            <td>Evaluated Date</td>
                            <td>{{ playerDate(selected.evaluated_at) }}</td>
                        </tr>
                        <tr>
                            <td>Remarks</td>
                            <td class="text-break">{{ selected.remarks || '-' }}</td>
                        </tr>
                        <tr v-if="selected.notes">
                            <td>Notes</td>
                            <td class="text-break">{{ selected.notes }}</td>
                        </tr>
                    </tbody></v-table
                >
                <v-alert v-if="!selected.player_id" type="warning" variant="tonal" class="my-4"
                    >The player identity must be linked before processing this request.</v-alert
                >
                <form v-if="actions.length" class="mt-4" @submit.prevent="submitDecision">
                    <v-textarea
                        v-model="decision.remarks"
                        label="Remarks"
                        maxlength="300"
                        counter="300"
                        :error-messages="decision.errors.remarks"
                    />
                    <v-alert
                        v-if="Object.values(decision.errors).length"
                        type="error"
                        variant="tonal"
                        class="my-4"
                        role="alert"
                        >{{ Object.values(decision.errors)[0] }}</v-alert
                    >
                    <template v-if="confirmAction">
                        <p class="my-4">
                            Confirm {{ confirmAction }} for
                            {{ playerMoney(selected.amount) }} tokens?<template
                                v-if="confirmAction === 'approve'"
                            >
                                This will
                                {{ kind === 'cashin' ? 'add tokens to' : 'deduct tokens from' }} the
                                player's wallet.</template
                            >
                        </p>
                        <div class="d-flex justify-end ga-2">
                            <v-btn
                                variant="text"
                                :disabled="decision.processing"
                                @click="confirmAction = null"
                                >Back</v-btn
                            ><v-btn type="submit" color="primary" :loading="decision.processing"
                                >Confirm</v-btn
                            >
                        </div>
                    </template>
                    <div v-else class="d-flex justify-end ga-2">
                        <v-btn
                            v-for="option in actions"
                            :key="option.action"
                            :color="
                                ['reject', 'disapprove'].includes(option.action)
                                    ? 'error'
                                    : 'primary'
                            "
                            @click="confirmAction = option.action"
                            >{{ option.label }}</v-btn
                        >
                    </div>
                </form>
                <v-btn
                    variant="text"
                    class="mt-4 align-self-end"
                    :disabled="decision.processing"
                    @click="selected = null"
                    >Close</v-btn
                >
            </v-card>
        </v-dialog>
        <v-dialog
            v-model="manualOpen"
            max-width="520"
            :persistent="manualForm.processing"
            class="players-dialog"
        >
            <v-card class="pa-6"
                ><h2 class="mb-4">Manual {{ title }}</h2>
                <form @submit.prevent="submitManual">
                    <v-autocomplete
                        v-model="manualForm.player_id"
                        :items="playerOptions"
                        :item-title="
                            (player: CashierPlayer) => `${player.name} (${player.mobile_number})`
                        "
                        item-value="id"
                        label="Player"
                        placeholder="Search name or mobile (at least 3 characters)"
                        no-filter
                        clearable
                        :loading="playerLoading"
                        :error-messages="manualForm.errors.player_id || playerSearchError"
                        no-data-text="Search for an active, verified player"
                        @update:search="searchPlayers"
                    />
                    <p v-if="chosenPlayer" class="text-caption my-3">
                        Wallet Token: {{ playerMoney(chosenPlayer.wallet) }}
                    </p>
                    <v-text-field
                        v-model="manualForm.amount"
                        label="Token"
                        type="number"
                        min="0.01"
                        max="99999999.99"
                        step="0.01"
                        required
                        :error-messages="manualForm.errors.amount"
                        class="my-4"
                    />
                    <v-textarea
                        v-model="manualForm.remarks"
                        label="Remarks"
                        maxlength="300"
                        counter="300"
                        required
                        :error-messages="manualForm.errors.remarks"
                        class="mb-4"
                    />
                    <v-text-field
                        v-model="manualForm.approval_password"
                        label="Approval Password"
                        type="password"
                        autocomplete="off"
                        required
                        :error-messages="manualForm.errors.approval_password"
                        class="mb-4"
                    />
                    <v-alert
                        v-if="Object.values(manualForm.errors).length"
                        type="error"
                        variant="tonal"
                        class="mb-4"
                        role="alert"
                        >{{ Object.values(manualForm.errors)[0] }}</v-alert
                    >
                    <p class="text-caption mb-4">
                        Confirming will
                        {{ kind === 'cashin' ? 'add tokens to' : 'deduct tokens from' }} the
                        selected player's wallet immediately.
                    </p>
                    <div class="d-flex justify-end ga-2">
                        <v-btn
                            variant="text"
                            :disabled="manualForm.processing"
                            @click="manualOpen = false"
                            >Cancel</v-btn
                        ><v-btn
                            type="submit"
                            color="primary"
                            :loading="manualForm.processing"
                            :disabled="!manualForm.player_id"
                            >Confirm {{ title }}</v-btn
                        >
                    </div>
                </form>
            </v-card>
        </v-dialog>
    </PlayerShell>
</template>
