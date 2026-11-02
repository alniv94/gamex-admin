<script setup lang="ts">
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import { useTableFilters } from '@/composables/useTableFilters';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { exportTableItems } from '@/composables/useTableExport';
import AdminDataTable from '@/components/AdminDataTable.vue';
import type { DataTableHeader } from 'vuetify';
import { index, show } from '@/actions/App/Http/Controllers/AgentController';
import AgentForm from '@/components/agents/AgentForm.vue';
import AgentShell from '@/components/agents/AgentShell.vue';
import AgentSortHeader from '@/components/agents/AgentSortHeader.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import { usePermissions } from '@/composables/usePermissions';
import type { AgentFilters, AgentRow } from '@/types/agents';
import { agentName } from '@/types/agents';
import type { PageResult } from '@/types/ending';
import { playerDate, playerMoney } from '@/types/players';
import { Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import { VBtn } from 'vuetify/components';

const props = defineProps<{
    records: PageResult<AgentRow>;
    filters: AgentFilters;
    outlets: { id: number; outlet_name: string }[];
    ready: boolean;
}>();
const { can } = usePermissions();
const page = usePage();
const filters = reactive({ ...props.filters });
watch(
    () => props.filters,
    (value) => Object.assign(filters, value),
);
const busy = ref(false);
const editor = ref(false);
const editing = ref<AgentRow>();
const filterOpen = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
const columns = [
    ['Name', 'name'],
    ['Mobile number', 'mobile_number'],
    ['Email', 'email_address'],
    ['Username', 'username'],
    ['Players', 'player_count'],
    ['Verified players', 'verified_count'],
    ['Commission', 'commission'],
    ['Wallet', 'wallet'],
    ['Status', 'status'],
    ['Created at', 'created_at'],
];
function visit() {
    clearTimeout(timer);
    router.get(
        index.url(),
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
function edit(account?: AgentRow) {
    editing.value = account;
    editor.value = true;
}
onBeforeUnmount(() => clearTimeout(timer));

const tableHeaders: DataTableHeader<AgentRow>[] = [
    { title: 'Actions', key: 'actions', sortable: false },
    { title: 'Name', key: 'first_name', value: agentName, sortable: true },
    { title: 'Mobile number', key: 'mobile_number', sortable: true },
    { title: 'Email', key: 'email_address', sortable: true },
    { title: 'Username', key: 'username', sortable: true },
    { title: 'Players', key: 'player_count', sortable: true },
    { title: 'Verified players', key: 'verified_count', sortable: true },
    { title: 'Commission', key: 'commission', sortable: true },
    { title: 'Wallet', key: 'wallet', sortable: true },
    { title: 'Status', key: 'is_active', sortable: true },
    { title: 'Created at', key: 'created_at', sortable: true },
];

const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => [
        'search',
        'status',
        'type',
        {
            key: 'outlet_id',
            options: props.outlets.map((outlet) => ({
                title: outlet.outlet_name,
                value: outlet.id,
            })),
        },
        'date_range',
    ],
    (changes) => {
        Object.assign(filters, changes);
        visit();
    },
    undefined,
);
const draft = reactive({ ...filters });
function openFilters() {
    Object.assign(draft, props.filters);
    filterOpen.value = true;
}
function applyFilters() {
    Object.assign(filters, draft);
    visit();
}
</script>

<template>
    <AgentShell title="Agent List" :ready="ready">
        <v-card class="agents-panel">
            <AdminTableToolbar
                :filters-open="filterOpen"
                :active-filters="activeFilters"
                @remove-filter="removeFilter"
                @reset-filters="resetTableFilters"
                :total="records.total"
                v-model="filters.search"
                search-label="Search agents"
                refresh-label="Refresh agents"
                :exports="[
                    {
                        title: 'Current page (CSV)',
                        action: () =>
                            exportTableItems('agents-index.csv', records.data, tableHeaders),
                    },
                ]"
                @refresh="visit"
                @search="search"
                :loading="busy"
                filterable
                @filter="openFilters"
                filter-label="Filter agents"
            >
                <template #actions
                    ><v-btn
                        v-if="can('Ending Agents Create')"
                        prepend-icon="mdi-plus"
                        color="primary"
                        :disabled="!ready"
                        @click="edit()"
                        >Create agent</v-btn
                    ></template
                >
            </AdminTableToolbar>

            <v-progress-linear v-if="busy" indeterminate color="primary" />
            <AdminDataTable
                :headers="tableHeaders"
                :items="records.data"
                server
                :toolbar="false"
                title="Agents"
            >
                <template #headers>
                    <tr>
                        <th>Actions</th>
                        <AgentSortHeader
                            v-for="[title, key] in columns"
                            :key="key"
                            :label="title!"
                            :column="key!"
                            :sort="filters.sort"
                            :direction="filters.direction"
                            :disabled="busy"
                            @sort="sort"
                        />
                    </tr>
                </template>
                <template #item="{ item: agent }"
                    ><tr :key="agent.id">
                        <td class="text-no-wrap">
                            <v-btn
                                icon="mdi-pencil-outline"
                                size="small"
                                variant="text"
                                color="primary"
                                :aria-label="`Edit ${agentName(agent)}`"
                                :disabled="!ready"
                                @click="edit(agent)"
                            />
                            <Link
                                :as="VBtn"
                                :href="show(agent.id, { query: { assign: 1 } })"
                                icon="mdi-account-plus-outline"
                                size="small"
                                variant="text"
                                color="primary"
                                :aria-label="`Add player to ${agentName(agent)}`"
                            />
                            <Link
                                :as="VBtn"
                                :href="show(agent.id)"
                                icon="mdi-account-group-outline"
                                size="small"
                                variant="text"
                                color="primary"
                                :aria-label="`View ${agentName(agent)} players`"
                            />
                            <Link
                                :as="VBtn"
                                :href="show(agent.id, { query: { reset: 1 } })"
                                icon="mdi-key-outline"
                                size="small"
                                variant="text"
                                color="primary"
                                :aria-label="`Reset ${agentName(agent)} password`"
                            />
                        </td>
                        <td class="text-no-wrap">
                            <Link :href="show(agent.id)">{{ agentName(agent) }}</Link>
                        </td>
                        <td class="text-no-wrap">
                            {{ agent.mobile_number ? '+63' + agent.mobile_number : '—' }}
                        </td>
                        <td>{{ agent.email_address || '—' }}</td>
                        <td>
                            {{ agent.username
                            }}<v-chip
                                v-if="Number(agent.username_conflicts) > 0"
                                size="x-small"
                                color="warning"
                                class="ml-2"
                                >Duplicate username</v-chip
                            >
                        </td>
                        <td>{{ Number(agent.player_count).toLocaleString() }}</td>
                        <td>{{ Number(agent.verified_count).toLocaleString() }}</td>
                        <td>{{ playerMoney(agent.commission) }}%</td>
                        <td class="text-no-wrap">PHP {{ playerMoney(agent.wallet) }}</td>
                        <td>
                            <v-chip
                                size="small"
                                :color="agent.is_active === 'Active' ? 'success' : 'grey'"
                                >{{ agent.is_active }}</v-chip
                            >
                        </td>
                        <td class="text-no-wrap">{{ playerDate(agent.created_at) }}</td>
                    </tr></template
                >
                <template #no-data>No agents match your filters.</template>
            </AdminDataTable>
            <EndingPager :page="records" selectable />
        </v-card>
        <AgentForm v-model="editor" :account="editing" :outlets="outlets" />
        <AdminFilterDialog
            v-model="filterOpen"
            :loading="busy"
            @apply="applyFilters"
            @reset="resetTableFilters"
        >
            <v-row dense>
                <v-col cols="12" sm="6"
                    ><v-select
                        v-model="draft.status"
                        :items="['All', 'Active', 'Inactive']"
                        label="Status"
                        hide-details
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-select
                        v-model="draft.type"
                        :items="['All', 'Regular', 'Special']"
                        label="Agent type"
                        hide-details
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-select
                        v-model="draft.outlet_id"
                        :items="outlets"
                        item-title="outlet_name"
                        item-value="id"
                        label="Outlet"
                        clearable
                        hide-details
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.date_from"
                        :error-messages="page.props.errors.date_from"
                        type="date"
                        label="Created from"
                        hide-details
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.date_to"
                        :error-messages="page.props.errors.date_to"
                        type="date"
                        label="Created to"
                        hide-details
                /></v-col>
            </v-row> </AdminFilterDialog
    ></AgentShell>
</template>
