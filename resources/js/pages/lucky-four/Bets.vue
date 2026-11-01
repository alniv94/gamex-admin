<script setup lang="ts">
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
    form.get(betsRoute.url(), { preserveState: true });
}
</script>
<template>
    <LuckyFourShell title="Bets" :migration-ready="migrationReady">
        <v-card>
            <form class="pa-4" @submit.prevent="filter">
                <v-row dense>
                    <v-col cols="12" md="3"
                        ><v-text-field
                            v-model="form.search"
                            label="Player, combination or Ending bet"
                            hide-details
                    /></v-col>
                    <v-col cols="12" sm="4" md="2"
                        ><v-text-field
                            v-model="form.game_id"
                            label="Game ID"
                            type="number"
                            min="1"
                            :error-messages="form.errors.game_id"
                    /></v-col>
                    <v-col cols="6" sm="4" md="2"
                        ><v-text-field
                            v-model="form.date_from"
                            label="Bet from (Manila)"
                            type="date"
                            :error-messages="form.errors.date_from"
                    /></v-col>
                    <v-col cols="6" sm="4" md="2"
                        ><v-text-field
                            v-model="form.date_to"
                            label="Bet to (Manila)"
                            type="date"
                            :error-messages="form.errors.date_to"
                    /></v-col>
                    <v-col cols="12" md="3" class="d-flex flex-wrap ga-2"
                        ><v-btn type="submit" color="primary" :loading="form.processing"
                            >Filter</v-btn
                        ><v-btn variant="text" @click="router.visit(betsRoute())"
                            >Reset</v-btn
                        ></v-col
                    >
                </v-row>
            </form>
            <div class="px-4 pb-4">
                <v-btn
                    :href="exportBets.url({ query: { ...filters } })"
                    variant="outlined"
                    prepend-icon="mdi-download"
                    >Export CSV</v-btn
                >
            </div>
            <v-table class="ending-table"
                ><thead>
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
                </thead>
                <tbody>
                    <tr v-for="bet in bets.data" :key="bet.id">
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
                    </tr>
                    <tr v-if="!bets.data.length">
                        <td colspan="10" class="text-center pa-6">No bets match these filters.</td>
                    </tr>
                </tbody></v-table
            ><EndingPager :page="bets" selectable />
        </v-card>
    </LuckyFourShell>
</template>
