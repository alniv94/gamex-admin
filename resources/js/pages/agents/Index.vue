<script setup lang="ts">
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
</script>

<template>
    <AgentShell title="Agent List" :ready="ready">
        <v-card class="agents-panel">
            <div class="d-flex flex-wrap align-center ga-2 pa-4">
                <v-btn
                    v-if="can('Ending Agents Create')"
                    prepend-icon="mdi-plus"
                    color="primary"
                    :disabled="!ready"
                    @click="edit()"
                    >Create agent</v-btn
                >
                <v-btn
                    icon="mdi-refresh"
                    variant="text"
                    aria-label="Refresh agents"
                    :loading="busy"
                    @click="visit"
                />
                <v-spacer />
                <v-text-field
                    v-model="filters.search"
                    label="Search agents"
                    prepend-inner-icon="mdi-magnify"
                    hide-details
                    clearable
                    density="compact"
                    style="max-width: 360px; min-width: 200px"
                    @update:model-value="search"
                />
                <v-btn
                    icon="mdi-filter-variant"
                    variant="text"
                    aria-label="Filter agents"
                    :aria-expanded="filterOpen"
                    @click="filterOpen = !filterOpen"
                />
            </div>
            <form v-if="filterOpen" class="pa-4 border-t" @submit.prevent="visit">
                <v-row dense>
                    <v-col cols="12" sm="4"
                        ><v-select
                            v-model="filters.status"
                            :items="['All', 'Active', 'Inactive']"
                            label="Status"
                            hide-details
                    /></v-col>
                    <v-col cols="12" sm="4"
                        ><v-select
                            v-model="filters.type"
                            :items="['All', 'Regular', 'Special']"
                            label="Agent type"
                            hide-details
                    /></v-col>
                    <v-col cols="12" sm="4"
                        ><v-select
                            v-model="filters.outlet_id"
                            :items="outlets"
                            item-title="outlet_name"
                            item-value="id"
                            label="Outlet"
                            clearable
                            hide-details
                    /></v-col>
                    <v-col cols="12" sm="4"
                        ><v-text-field
                            v-model="filters.date_from"
                            :error-messages="page.props.errors.date_from"
                            type="date"
                            label="Created from"
                            hide-details
                    /></v-col>
                    <v-col cols="12" sm="4"
                        ><v-text-field
                            v-model="filters.date_to"
                            :error-messages="page.props.errors.date_to"
                            type="date"
                            label="Created to"
                            hide-details
                    /></v-col>
                    <v-col cols="12" sm="4" class="d-flex align-center"
                        ><v-btn type="submit" color="primary" :loading="busy"
                            >Apply filters</v-btn
                        ></v-col
                    >
                </v-row>
            </form>
            <v-progress-linear v-if="busy" indeterminate color="primary" />
            <v-table class="ending-table" density="compact">
                <thead>
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
                </thead>
                <tbody>
                    <tr v-for="agent in records.data" :key="agent.id">
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
                    </tr>
                    <tr v-if="!records.data.length">
                        <td colspan="11" class="text-center pa-8">No agents match your filters.</td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="records" selectable />
        </v-card>
        <AgentForm v-model="editor" :account="editing" :outlets="outlets" />
    </AgentShell>
</template>
