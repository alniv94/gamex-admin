<script setup lang="ts">
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import { useTableFilters } from '@/composables/useTableFilters';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { exportTableItems } from '@/composables/useTableExport';
import AdminDataTable from '@/components/AdminDataTable.vue';
import type { DataTableHeader } from 'vuetify';
import { reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    bets as betsRoute,
    exportBets,
    show,
} from '@/actions/App/Http/Controllers/LuckyFourController';
import LuckyFourShell from '@/components/lucky-four/LuckyFourShell.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import { money, displayDate } from '@/types/ending';
import type { PageResult } from '@/types/ending';
import type { LuckyFourBet, LuckyFourFilters } from '@/types/lucky-four';
const props = defineProps<{
    bets: PageResult<LuckyFourBet>;
    filters: LuckyFourFilters;
    migrationReady: boolean;
}>();
const form = useForm({
    search: props.filters.search || '',
    game_id: props.filters.game_id || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
});
function filter() {
    form.transform((data) => ({ ...data, per_page: props.bets.per_page })).get(betsRoute.url(), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => (filterOpen.value = false),
    });
}

const tableHeaders: DataTableHeader[] = [
    { title: 'Ending bet', key: 'ending_bet_id', sortable: true },
    { title: 'Game', key: 'game_id', sortable: true },
    { title: 'Player', key: 'player_name', sortable: true },
    { title: 'Combination', key: 'combination', sortable: true },
    { title: 'Amount', key: 'amount', sortable: true },
    { title: 'Pool status', key: 'is_added', sortable: true },
    { title: 'Winner status', key: 'winner_status', sortable: true },
    { title: 'Prize', key: 'prize_amount', sortable: true },
    { title: 'Released', key: 'prize_released', sortable: true },
    { title: 'Bet at', key: 'created_at', sortable: true },
];

const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => ['search', 'game_id', 'date_range'],
    (changes) => {
        Object.assign(form, changes);
        filter();
    },
    undefined,
);
const filterOpen = ref(false);
const draft = reactive({ ...form.data() });
function openFilters() {
    Object.assign(draft, form.data());
    filterOpen.value = true;
}
function applyFilters() {
    Object.assign(form, draft);
    filter();
}
</script>
<template>
    <LuckyFourShell title="Bets" :migration-ready="migrationReady">
        <v-card>
            <AdminTableToolbar
                :filters-open="filterOpen"
                filterable
                @filter="openFilters"
                :active-filters="activeFilters"
                @remove-filter="removeFilter"
                @reset-filters="resetTableFilters"
                :total="bets.total"
                v-model="form.search"
                search-label="Search bets"
                :loading="form.processing"
                :exports="[
                    {
                        title: 'All matching bets (CSV)',
                        href: exportBets.url({ query: { ...filters } }),
                    },
                    {
                        title: 'Current page (CSV)',
                        action: () =>
                            exportTableItems('lucky-four-bets.csv', bets.data, tableHeaders),
                    },
                ]"
                @search="filter"
                @refresh="filter"
            ></AdminTableToolbar>

            <AdminDataTable
                :headers="tableHeaders"
                :items="bets.data"
                server
                :toolbar="false"
                title="Bets"
            >
                <template #headers>
                    <tr>
                        <th>Ending bet</th>
                        <th>Game</th>
                        <th>Player</th>
                        <th>Combination</th>
                        <th>Amount</th>
                        <th>Pool status</th>
                        <th>Winner status</th>
                        <th>Prize</th>
                        <th>Released</th>
                        <th>Bet at</th>
                    </tr>
                </template>
                <template #item="{ item: bet }"
                    ><tr :key="bet.id">
                        <td>#{{ bet.ending_bet_id }}</td>
                        <td>
                            <v-btn
                                v-if="bet.game_id"
                                variant="text"
                                size="small"
                                @click="router.visit(show(bet.game_id))"
                                >#{{ bet.game_id }}</v-btn
                            ><span v-else>Pending import</span>
                        </td>
                        <td>{{ bet.player_name || 'Unresolved player' }}</td>
                        <td class="font-weight-bold text-monospace">
                            {{ bet.combination ?? '—' }}
                        </td>
                        <td>{{ money(bet.amount) }}</td>
                        <td>{{ bet.is_added ?? '—' }}</td>
                        <td>{{ bet.winner_status || '—' }}</td>
                        <td>{{ bet.prize_amount === null ? '—' : money(bet.prize_amount) }}</td>
                        <td>{{ bet.prize_released || '—' }}</td>
                        <td>{{ displayDate(bet.created_at) }}</td>
                    </tr></template
                >
                <template #no-data>No bets match these filters.</template> </AdminDataTable
            ><EndingPager :page="bets" selectable />
        </v-card>
        <AdminFilterDialog
            v-model="filterOpen"
            :loading="form.processing"
            @apply="applyFilters"
            @reset="resetTableFilters"
        >
            <v-row dense>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.game_id"
                        label="Game ID"
                        type="number"
                        min="1"
                        :error-messages="form.errors.game_id"
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.date_from"
                        label="Bet from (Manila)"
                        type="date"
                        :error-messages="form.errors.date_from"
                /></v-col>
                <v-col cols="12" sm="6"
                    ><v-text-field
                        v-model="draft.date_to"
                        label="Bet to (Manila)"
                        type="date"
                        :error-messages="form.errors.date_to"
                /></v-col>
            </v-row> </AdminFilterDialog
    ></LuckyFourShell>
</template>
