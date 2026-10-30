<script setup lang="ts">
import {
    index,
    show,
    exportMethod as exportPlayers,
} from '@/actions/App/Http/Controllers/PlayerController';
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import PlayerShell from '../../components/players/PlayerShell.vue';
import PlayerStatus from '../../components/players/PlayerStatus.vue';
import OtherDetails from '../../components/players/OtherDetails.vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import type { PageResult } from '../../types/ending';
import type { PlayerRecord } from '../../types/players';
import { playerDate } from '../../types/players';
const props = defineProps<{ players: PageResult<PlayerRecord>; filters: Record<string, string> }>();
const page = usePage();
const form = reactive({
    search: '',
    status: 'Active',
    account_verified: 'Files Submitted',
    type: 'Players',
    date_from: '',
    date_to: '',
    sort: 'id',
    direction: 'desc',
});
watch(
    () => props.filters,
    (filters) =>
        Object.assign(form, {
            search: '',
            status: 'Active',
            account_verified: 'Files Submitted',
            type: 'Players',
            date_from: '',
            date_to: '',
            sort: 'id',
            direction: 'desc',
            ...filters,
        }),
    { immediate: true },
);
const draft = reactive({ status: '', account_verified: '', type: '', date_from: '', date_to: '' });
const filterOpen = ref(false);
const loading = ref(false);
const filterError = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
function visit() {
    clearTimeout(timer);
    router.get(
        index.url(),
        { ...form, per_page: props.players.per_page },
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
    timer = setTimeout(visit, 350);
}
function openFilters() {
    Object.assign(draft, form);
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
function sort(key: string) {
    form.direction = form.sort === key && form.direction === 'asc' ? 'desc' : 'asc';
    form.sort = key;
    visit();
}
const headers = [
    { title: 'ID', key: 'id' },
    { title: 'Action', key: '' },
    { title: 'Name', key: 'name' },
    { title: 'Mobile Number', key: '' },
    { title: 'Email Address', key: '' },
    { title: 'Verification Status', key: 'account_verified' },
    { title: 'Verified Date', key: 'verified_at' },
    { title: 'Registered Date', key: 'created_at' },
    { title: 'Others', key: '' },
];
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <PlayerShell :title="filters.duplicate_of ? 'Duplicate Players' : 'Player List'">
        <v-card>
            <div class="players-toolbar">
                <div class="d-flex ga-1">
                    <v-btn
                        icon="mdi-sync"
                        variant="text"
                        color="secondary"
                        aria-label="Refresh players"
                        title="Refresh"
                        :loading="loading"
                        @click="visit"
                    />
                    <v-btn
                        :href="exportPlayers.url({ query: { ...form } })"
                        icon="mdi-file-delimited-outline"
                        variant="text"
                        color="secondary"
                        aria-label="Export players to CSV"
                        title="Export to EXCEL"
                    />
                </div>
                <div class="players-search-controls">
                    <v-text-field
                        v-model="form.search"
                        placeholder="Search name, mobile, email"
                        aria-label="Search name, mobile, email"
                        hide-details
                        clearable
                        @update:model-value="search"
                        @keyup.enter="visit"
                    />
                    <v-btn
                        icon="mdi-filter-variant"
                        variant="text"
                        color="secondary"
                        aria-label="Filter players"
                        title="Filter"
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
                >{{ Object.values(page.props.errors)[0] }}</v-alert
            >
            <v-table class="players-table">
                <thead>
                    <tr>
                        <th
                            v-for="(header, n) in headers"
                            :key="n"
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
                    <tr v-for="player in players.data" :key="player.id">
                        <td>{{ player.id }}</td>
                        <td>
                            <v-btn
                                :href="show.url(player.id)"
                                target="_blank"
                                rel="noopener"
                                icon="mdi-text-box-search-outline"
                                variant="text"
                                color="primary"
                                size="small"
                                :aria-label="`Details for ${player.name}`"
                                title="Details"
                            />
                        </td>
                        <td class="players-name">{{ player.name || '-' }}</td>
                        <td>{{ player.mobile_number || '-' }}</td>
                        <td>{{ player.email_address || '' }}</td>
                        <td><PlayerStatus :status="player.account_verified" /></td>
                        <td>{{ playerDate(player.verified_at) }}</td>
                        <td>{{ playerDate(player.created_at) }}</td>
                        <td><OtherDetails :player="player" /></td>
                    </tr>
                    <tr v-if="!players.data.length">
                        <td colspan="9" class="text-center text-medium-emphasis py-8">
                            No data available
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="players" selectable />
        </v-card>
        <v-dialog v-model="filterOpen" max-width="500" :persistent="loading" class="players-dialog">
            <v-card class="pa-6">
                <h2 class="mb-5">Filter</h2>
                <form @submit.prevent="applyFilters">
                    <v-select
                        v-model="draft.type"
                        label="Type"
                        :items="['Players', 'Duplicate Players']"
                        variant="outlined"
                        density="compact"
                        class="mb-4"
                    />
                    <v-select
                        v-model="draft.status"
                        label="Status"
                        :items="['All', 'Active', 'Inactive']"
                        variant="outlined"
                        density="compact"
                        class="mb-4"
                    />
                    <v-select
                        v-model="draft.account_verified"
                        label="Verification Status"
                        :items="['All', 'Verified', 'Pending', 'Files Submitted', 'Rejected']"
                        variant="outlined"
                        density="compact"
                        class="mb-4"
                    />
                    <v-text-field
                        v-model="draft.date_from"
                        type="date"
                        label="Date From"
                        class="mb-4"
                    />
                    <v-text-field
                        v-model="draft.date_to"
                        type="date"
                        label="Date To"
                        :min="draft.date_from || undefined"
                        class="mb-4"
                        :error-messages="filterError"
                    />
                    <div class="d-flex justify-end ga-2">
                        <v-btn variant="text" :disabled="loading" @click="filterOpen = false"
                            >Cancel</v-btn
                        ><v-btn type="submit" color="primary" :loading="loading">Apply</v-btn>
                    </div>
                </form>
            </v-card>
        </v-dialog>
    </PlayerShell>
</template>
