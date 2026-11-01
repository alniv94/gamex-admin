<script setup lang="ts">
import { reactive, ref, onBeforeUnmount } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    bets as betsRoute,
    show,
    pdf,
    exportBets,
} from '@/actions/App/Http/Controllers/OtsoPanaloController';
import { usePermissions } from '@/composables/usePermissions';
import OtsoShell from '@/components/otso/OtsoShell.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import { displayDate, money } from '@/types/ending';
import type { PageResult } from '@/types/ending';
import { quarters } from '@/types/otso';
import type { OtsoBet, OtsoGame } from '@/types/otso';
const props = defineProps<{
    bets: PageResult<OtsoBet>;
    games: OtsoGame[];
    filters: Record<string, string>;
}>();
const { can } = usePermissions();
const selected = ref<OtsoBet | null>(null);
const filters = reactive({
    search: props.filters.search || '',
    game_id: Number(props.filters.game_id) || null,
    filter_by: props.filters.filter_by || 'Bet Date',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
});
function filter() {
    router.get(
        betsRoute.url(),
        { ...filters, per_page: props.bets.per_page },
        { preserveState: true, replace: true },
    );
}
let timer: ReturnType<typeof setTimeout> | undefined;
function search() {
    clearTimeout(timer);
    timer = setTimeout(filter, 300);
}
onBeforeUnmount(() => clearTimeout(timer));
</script>
<template>
    <OtsoShell title="Bets">
        <v-card>
            <div class="ending-toolbar d-flex ga-3 align-center flex-wrap pa-4">
                <h2>Bet Records</h2>
                <v-spacer /><v-text-field
                    v-model="filters.search"
                    label="Search bets"
                    prepend-inner-icon="mdi-magnify"
                    hide-details
                    class="ending-search"
                    @update:model-value="search"
                /><v-btn
                    icon="mdi-refresh"
                    variant="text"
                    aria-label="Refresh bets"
                    @click="filter"
                />
                <v-btn
                    :href="exportBets.url({ query: filters })"
                    prepend-icon="mdi-download"
                    variant="outlined"
                    >Export CSV</v-btn
                >
            </div>
            <v-form class="px-4 pb-4" @submit.prevent="filter"
                ><v-row dense>
                    <v-col cols="12" md="4"
                        ><v-autocomplete
                            v-model="filters.game_id"
                            :items="
                                games.map((game) => ({
                                    title:
                                        '#' +
                                        game.id +
                                        ' · ' +
                                        game.team_a_name +
                                        ' vs ' +
                                        game.team_b_name,
                                    value: game.id,
                                }))
                            "
                            clearable
                            label="Game"
                            hide-details
                    /></v-col>
                    <v-col cols="12" sm="4" md="2"
                        ><v-select
                            v-model="filters.filter_by"
                            :items="['Bet Date', 'Game Schedule']"
                            label="Filter By"
                            hide-details
                    /></v-col>
                    <v-col cols="12" sm="4" md="2"
                        ><v-text-field
                            v-model="filters.date_from"
                            type="date"
                            label="Date From"
                            hide-details
                    /></v-col>
                    <v-col cols="12" sm="4" md="2"
                        ><v-text-field
                            v-model="filters.date_to"
                            type="date"
                            label="Date To"
                            hide-details
                    /></v-col>
                    <v-col cols="12" md="2" class="d-flex align-center"
                        ><v-btn type="submit" variant="outlined">Apply Filters</v-btn></v-col
                    >
                </v-row></v-form
            >
            <div v-if="filters.game_id && can('Ending Game PDF Generation')" class="px-4 pb-4">
                <v-btn
                    :href="pdf.url(filters.game_id)"
                    prepend-icon="mdi-file-pdf-box"
                    variant="outlined"
                    >Generate Game Bets PDF</v-btn
                >
            </div>
            <v-table class="ending-table"
                ><thead>
                    <tr>
                        <th>ID</th>
                        <th>Player</th>
                        <th>Game</th>
                        <th>Bet Type</th>
                        <th>Ticket Name</th>
                        <th v-for="quarter in quarters" :key="quarter.number">
                            Q{{ quarter.number }}
                        </th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Bet At</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="bet in bets.data" :key="bet.id">
                        <td>#{{ bet.id }}</td>
                        <td>{{ bet.username || bet.player_name || 'Unlinked player' }}</td>
                        <td>
                            <Link :href="show(bet.game_id)"
                                >#{{ bet.game_id }} {{ bet.team_a_name }} vs
                                {{ bet.team_b_name }}</Link
                            >
                            <div class="text-caption">
                                {{ bet.league }} · {{ displayDate(bet.schedule) }}
                            </div>
                        </td>
                        <td>{{ bet.bet_type }}</td>
                        <td>{{ bet.name || '—' }}</td>
                        <td v-for="quarter in quarters" :key="quarter.number">
                            <span
                                :class="
                                    bet[quarter.status] === 'Win'
                                        ? 'text-success font-weight-bold'
                                        : ''
                                "
                                >{{ bet[quarter.combination] || '—' }}</span
                            >
                            <div v-if="bet[quarter.status]" class="text-caption">
                                {{ bet[quarter.status] }}
                            </div>
                        </td>
                        <td>{{ money(bet.amount) }}</td>
                        <td>
                            <v-chip v-if="bet.refunded === 'Yes'" size="small" color="warning"
                                >Refunded</v-chip
                            ><v-chip v-else-if="bet.winner_status" size="small" color="success">{{
                                bet.winner_status
                            }}</v-chip
                            ><span v-else>Recorded</span>
                        </td>
                        <td>{{ displayDate(bet.created_at) }}</td>
                        <td>
                            <v-btn
                                icon="mdi-eye"
                                variant="text"
                                size="small"
                                aria-label="View bet"
                                @click="selected = bet"
                            />
                        </td>
                    </tr>
                    <tr v-if="!bets.data.length">
                        <td colspan="13" class="text-center pa-6">No bets found.</td>
                    </tr>
                </tbody></v-table
            ><EndingPager :page="bets" selectable :page-sizes="[10, 25, 50, 100, 500]" />
        </v-card>
        <v-dialog :model-value="!!selected" max-width="600" @update:model-value="selected = null"
            ><v-card v-if="selected"
                ><v-card-title>Bet #{{ selected.id }}</v-card-title
                ><v-card-text
                    ><p>
                        Player: {{ selected.username || selected.player_name || 'Unlinked player' }}
                    </p>
                    <p>Ticket: {{ selected.name || '—' }}</p>
                    <p>Bet type: {{ selected.bet_type }}</p>
                    <p>Amount: PHP {{ money(selected.amount) }}</p>
                    <p>Prize: PHP {{ money(selected.prize_amount) }}</p>
                    <p>Refunded: {{ selected.refunded }}</p>
                    <p>Legacy ID: {{ selected.legacy_id || '—' }}</p>
                    <p v-for="quarter in quarters" :key="quarter.number">
                        {{ quarter.label }}: {{ selected[quarter.combination] || '—' }}
                        {{ selected[quarter.status] || '' }}
                    </p></v-card-text
                ><v-card-actions
                    ><v-btn @click="selected = null">Close</v-btn></v-card-actions
                ></v-card
            ></v-dialog
        >
    </OtsoShell>
</template>
