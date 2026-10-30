<script setup lang="ts">
import { show } from '@/actions/App/Http/Controllers/PlayerController';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import EndingPager from '../ending/EndingPager.vue';
import type { PageResult } from '../../types/ending';
import type { PlayerActivity } from '../../types/players';
import { playerDate, playerMoney } from '../../types/players';
const props = defineProps<{
    playerId: number;
    activity: PageResult<PlayerActivity>;
    filters: Record<string, string>;
}>();
const tab = ref('transactions');
const search = ref('');
const loading = ref(false);
const filterOpen = ref(false);
const dates = reactive({ activity_date_from: '', activity_date_to: '' });
const error = ref('');
watch(
    () => props.filters,
    (filters) => {
        tab.value = filters.tab || 'transactions';
        search.value = filters.activity_search || '';
        Object.assign(dates, {
            activity_date_from: filters.activity_date_from || '',
            activity_date_to: filters.activity_date_to || '',
        });
    },
    { immediate: true },
);
let timer: ReturnType<typeof setTimeout> | undefined;
function visit(nextTab = tab.value) {
    clearTimeout(timer);
    router.get(
        show.url(props.playerId),
        {
            tab: nextTab,
            activity_search: search.value,
            ...dates,
            per_page: props.activity.per_page,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['activity', 'filters'],
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
            onSuccess: () => (filterOpen.value = false),
        },
    );
}
function debounceSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => visit(), 350);
}
function applyDates() {
    if (
        dates.activity_date_from &&
        dates.activity_date_to &&
        dates.activity_date_to < dates.activity_date_from
    ) {
        error.value = 'Date To must be on or after Date From.';
        return;
    }
    error.value = '';
    visit();
}
onBeforeUnmount(() => clearTimeout(timer));
</script>
<template>
    <v-card>
        <v-tabs
            :model-value="tab"
            color="primary"
            density="compact"
            @update:model-value="(value) => visit(String(value))"
        >
            <v-tab value="transactions">Transactions</v-tab
            ><v-tab value="player_logs">Player Logs</v-tab
            ><v-tab value="admin_logs">Admin Logs</v-tab>
        </v-tabs>
        <v-divider />
        <div class="players-toolbar">
            <v-btn
                icon="mdi-sync"
                variant="text"
                color="secondary"
                :loading="loading"
                aria-label="Refresh activity"
                @click="visit()"
            />
            <div class="players-search-controls activity-search">
                <v-text-field
                    v-model="search"
                    placeholder="Search"
                    aria-label="Search activity"
                    clearable
                    hide-details
                    @update:model-value="debounceSearch"
                    @keyup.enter="visit()"
                /><v-btn
                    icon="mdi-filter-variant"
                    variant="text"
                    color="secondary"
                    aria-label="Filter activity"
                    @click="filterOpen = true"
                />
            </div>
        </div>
        <v-progress-linear v-if="loading" indeterminate height="2" color="primary" />
        <v-divider />
        <v-table v-if="activity.data.length" class="players-table activity-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th v-if="tab === 'admin_logs'">Admin</th>
                    <th v-if="tab === 'transactions'">Transaction Type</th>
                    <th>Details</th>
                    <th v-if="tab === 'transactions'">Amount</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in activity.data" :key="`${row.origin || tab}-${row.id}`">
                    <td>{{ row.id }}</td>
                    <td v-if="tab === 'admin_logs'">{{ row.admin || '-' }}</td>
                    <td v-if="tab === 'transactions'">{{ row.transaction_type || '-' }}</td>
                    <td class="activity-description">{{ row.details || '-' }}</td>
                    <td v-if="tab === 'transactions'">{{ playerMoney(row.amount) }}</td>
                    <td>{{ playerDate(row.created_at) }}</td>
                </tr>
            </tbody>
        </v-table>
        <div v-else class="text-center text-medium-emphasis pa-6">No data available</div>
        <EndingPager v-if="activity.total" :page="activity" selectable />
        <v-dialog v-model="filterOpen" max-width="420" class="players-dialog"
            ><v-card class="pa-6"
                ><h2 class="mb-5">Filter</h2>
                <form @submit.prevent="applyDates">
                    <v-text-field
                        v-model="dates.activity_date_from"
                        label="Date From"
                        type="date"
                        class="mb-4"
                    /><v-text-field
                        v-model="dates.activity_date_to"
                        label="Date To"
                        type="date"
                        :min="dates.activity_date_from || undefined"
                        :error-messages="error"
                        class="mb-4"
                    />
                    <div class="d-flex justify-end ga-2">
                        <v-btn variant="text" @click="filterOpen = false">Cancel</v-btn
                        ><v-btn type="submit" color="primary" :loading="loading">Filter</v-btn>
                    </div>
                </form></v-card
            ></v-dialog
        >
    </v-card>
</template>
