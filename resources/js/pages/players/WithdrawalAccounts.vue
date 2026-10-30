<script setup lang="ts">
import { show, withdrawalAccounts } from '@/actions/App/Http/Controllers/PlayerController';
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import PlayerShell from '../../components/players/PlayerShell.vue';
import PlayerStatus from '../../components/players/PlayerStatus.vue';
import type { PageResult } from '../../types/ending';
import type { WithdrawalAccount } from '../../types/players';
import { playerDate } from '../../types/players';

const props = defineProps<{
    accounts: PageResult<WithdrawalAccount>;
    filters: Record<string, string | null>;
    firstAccountDate: string | null;
    today: string;
}>();
const page = usePage();
const form = reactive({
    search: '',
    date_from: '',
    date_to: '',
    sort: 'created_at',
    direction: 'desc',
});
watch(
    () => props.filters,
    (filters) => {
        form.search = filters.search ?? '';
        form.date_from = filters.date_from ?? '';
        form.date_to = filters.date_to ?? '';
        form.sort = filters.sort ?? 'created_at';
        form.direction = filters.direction ?? 'desc';
    },
    { immediate: true },
);
const draft = reactive({ date_from: '', date_to: '' });
const filterOpen = ref(false);
const filterError = ref('');
const loading = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

function visit(targetPage = 1) {
    clearTimeout(timer);
    router.get(
        withdrawalAccounts.url(),
        { ...form, per_page: props.accounts.per_page, page: targetPage },
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
    clearTimeout(timer);
    timer = setTimeout(() => visit(), 350);
}
function openFilters() {
    draft.date_from = form.date_from;
    draft.date_to = form.date_to || props.today;
    filterError.value = '';
    filterOpen.value = true;
}
function applyFilters() {
    if (!draft.date_from || !draft.date_to) {
        filterError.value = 'Date From and Date To are required.';
        return;
    }
    if (draft.date_to < draft.date_from) {
        filterError.value = 'Date To must be on or after Date From.';
        return;
    }
    Object.assign(form, draft);
    visit();
}
function resetFilters() {
    form.date_from = '';
    form.date_to = '';
    visit();
}
function sort(key: string) {
    form.direction = form.sort === key && form.direction === 'asc' ? 'desc' : 'asc';
    form.sort = key;
    visit();
}
function details(account: WithdrawalAccount) {
    if (account.player_id) router.visit(show(account.player_id));
}
const headers = [
    { title: 'Action', key: '' },
    { title: 'Outlet', key: 'bank' },
    { title: 'Account Number', key: 'account_number' },
    { title: 'Account Name', key: 'account_name' },
    { title: 'Status', key: 'is_verified' },
    { title: 'Date Created', key: 'created_at' },
];
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <PlayerShell title="Player Withdrawal Account">
        <v-card>
            <div class="players-toolbar">
                <v-btn
                    icon="mdi-cached"
                    variant="text"
                    color="secondary"
                    aria-label="Refresh withdrawal accounts"
                    title="Refresh"
                    :loading="loading"
                    @click="visit(accounts.current_page)"
                />
                <div class="players-search-controls">
                    <v-text-field
                        v-model="form.search"
                        placeholder="Search"
                        aria-label="Search withdrawal accounts"
                        hide-details
                        clearable
                        maxlength="100"
                        @update:model-value="search"
                        @keyup.enter="visit()"
                    />
                    <v-btn
                        icon="mdi-filter-variant"
                        variant="text"
                        color="secondary"
                        aria-label="Filter withdrawal accounts"
                        title="Filter"
                        :disabled="loading"
                        @click="openFilters"
                    />
                </div>
            </div>
            <v-progress-linear v-if="loading" indeterminate color="primary" height="2" />
            <v-divider />
            <v-alert
                v-if="Object.keys(page.props.errors).length"
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
                    <tr v-for="account in accounts.data" :key="account.id">
                        <td>
                            <v-btn
                                :href="account.player_id ? show.url(account.player_id) : undefined"
                                :disabled="!account.player_id"
                                icon="mdi-text-box-search-outline"
                                variant="text"
                                color="primary"
                                size="small"
                                :aria-label="`Details for withdrawal account ${account.account_number}`"
                                :title="account.player_id ? 'Details' : 'Player unavailable'"
                                @click.prevent="details(account)"
                            />
                        </td>
                        <td>{{ account.bank || '-' }}</td>
                        <td>{{ account.account_number }}</td>
                        <td>{{ account.account_name || '-' }}</td>
                        <td><PlayerStatus :status="account.is_verified" /></td>
                        <td>{{ playerDate(account.created_at) }}</td>
                    </tr>
                    <tr v-if="!accounts.data.length">
                        <td colspan="6" class="text-center text-medium-emphasis py-8">
                            No data available
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="accounts" :page-sizes="[5, 10, 15, 20, 25]" selectable />
        </v-card>
        <v-dialog v-model="filterOpen" max-width="400" :persistent="loading" class="players-dialog">
            <v-card class="pa-4 pa-md-6">
                <h2 class="mb-4">Filter</h2>
                <form @submit.prevent="applyFilters">
                    <v-text-field
                        v-model="draft.date_from"
                        type="date"
                        label="Date From"
                        prepend-icon="mdi-calendar"
                        :min="firstAccountDate || undefined"
                        :max="draft.date_to || today"
                        required
                        class="mb-4"
                    />
                    <v-text-field
                        v-model="draft.date_to"
                        type="date"
                        label="Date To"
                        prepend-icon="mdi-calendar"
                        :min="draft.date_from || firstAccountDate || undefined"
                        :max="today"
                        required
                        class="mb-4"
                        :error-messages="filterError"
                    />
                    <div class="d-flex justify-end flex-wrap ga-2">
                        <v-btn variant="text" :disabled="loading" @click="resetFilters"
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
    </PlayerShell>
</template>
