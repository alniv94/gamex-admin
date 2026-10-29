<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { cardIndex, show } from '@/actions/App/Http/Controllers/EndingGameController';
import EndingShell from '../../components/ending/EndingShell.vue';
import EndingToolbar from '../../components/ending/EndingToolbar.vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import EntityLogo from '../../components/ending/EntityLogo.vue';
import { exportEndingRows, useEndingSearch } from '../../composables/useEndingTable';
import type { Bet, BetAmount, EndingGame, GameCard, PageResult } from '../../types/ending';
import { betType, displayDate, money } from '../../types/ending';

const props = defineProps<{
    game: EndingGame;
    amount: Pick<BetAmount, 'id' | 'amount' | 'bet_type_id'> | null;
    cards: PageResult<GameCard>;
    filters: { search?: string; per_page?: number };
    cardBets?: Bet[];
}>();
const cardsUrl = cardIndex.url(props.game.id, {
    query: props.amount ? { bet_amount_id: props.amount.id } : {},
});
const { search, filter } = useEndingSearch(
    cardsUrl,
    props.filters.search,
    () => props.cards.per_page,
);
const selectedCard = ref<GameCard | null>(null);
const loading = ref(false);
const bets = computed(() => props.cardBets || []);
const cardNumber = (id: number) => `#${String(id).padStart(6, '0')}`;
const totalBetAmount = (card: GameCard) => money(Number(card.amount) * card.bets);

function inspect(card: GameCard) {
    selectedCard.value = card;
    loading.value = true;
    router.reload({
        only: ['cardBets'],
        data: { card_id: card.id },
        onFinish: () => (loading.value = false),
    });
}
function exportCards() {
    exportEndingRows(`ending-game-${props.game.id}-cards.csv`, [
        [
            'Card number',
            'Bet type',
            'Bet amount (PHP)',
            'Total bets',
            'Total bet amount (PHP)',
            'Betting',
        ],
        ...props.cards.data.map((card) => [
            cardNumber(card.id),
            betType(card.bet_type_id),
            money(card.amount),
            card.bets,
            totalBetAmount(card),
            card.betting,
        ]),
    ]);
}
</script>

<template>
    <EndingShell title="Game Cards">
        <Link
            :href="show.url(game.id)"
            class="d-inline-flex align-center ga-1 text-primary text-body-2 text-decoration-none mb-4"
        >
            <v-icon icon="mdi-arrow-left" size="18" /> Game #{{ game.id }}
        </Link>
        <v-card>
            <EndingToolbar
                v-model="search"
                exportable
                @search="filter"
                @refresh="router.reload()"
                @export="exportCards"
            />
            <div class="ending-card-matchup">
                <h2 class="text-subtitle-1 font-weight-medium">
                    {{ game.league || 'Ending Game' }}
                </h2>
                <p class="text-caption text-medium-emphasis mt-1">
                    {{ displayDate(game.schedule) }} · Philippines
                </p>
                <div class="ending-card-teams">
                    <div class="ending-card-team">
                        <EntityLogo :src="game.team_a_logo" :name="game.team_a_name" :size="72" />
                        <strong>{{ game.team_a_name }}</strong>
                    </div>
                    <span class="text-medium-emphasis font-italic">VS</span>
                    <div class="ending-card-team">
                        <EntityLogo :src="game.team_b_logo" :name="game.team_b_name" :size="72" />
                        <strong>{{ game.team_b_name }}</strong>
                    </div>
                </div>
                <div class="d-flex align-center justify-center flex-wrap ga-2">
                    <template v-if="amount">
                        <v-chip color="primary" variant="tonal" size="small"
                            >Bet amount: PHP {{ money(amount.amount) }}</v-chip
                        >
                        <v-chip variant="tonal" size="small">{{
                            betType(amount.bet_type_id)
                        }}</v-chip>
                    </template>
                    <v-chip v-else variant="tonal" size="small">All bet amounts</v-chip>
                </div>
            </div>
            <div
                class="d-flex align-center justify-space-between flex-wrap ga-2 px-4 py-3 border-t"
            >
                <h2 class="text-subtitle-2">Cards ({{ cards.total }})</h2>
                <span class="text-caption text-medium-emphasis"
                    >Search by card number · Select a card to view its bets</span
                >
            </div>
            <v-table class="ending-table ending-card-table" density="compact">
                <thead>
                    <tr>
                        <th class="text-center">Action</th>
                        <th>Card number</th>
                        <th v-if="!amount">Bet amount / type</th>
                        <th>Total bets</th>
                        <th>Total bet amount</th>
                        <th>Betting</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="card in cards.data" :key="card.id">
                        <td class="text-center">
                            <v-btn
                                icon="mdi-text-box-search-outline"
                                color="primary"
                                variant="text"
                                size="small"
                                :aria-label="`View card ${cardNumber(card.id)} details`"
                                title="View card details"
                                :disabled="loading"
                                @click="inspect(card)"
                            />
                        </td>
                        <td class="font-weight-medium text-no-wrap">{{ cardNumber(card.id) }}</td>
                        <td v-if="!amount" class="text-no-wrap">
                            PHP {{ money(card.amount) }}
                            <div class="text-caption text-medium-emphasis">
                                {{ betType(card.bet_type_id) }}
                            </div>
                        </td>
                        <td>
                            <div class="ending-card-bets">
                                <v-progress-linear
                                    :model-value="Math.min(card.bets, 100)"
                                    :color="card.bets > 70 ? 'success' : 'warning'"
                                    bg-opacity="0.15"
                                    height="24"
                                    rounded
                                    :aria-label="`${cardNumber(card.id)} total bets`"
                                >
                                    <strong class="text-body-2">{{ card.bets }}%</strong>
                                </v-progress-linear>
                                <span class="text-caption text-medium-emphasis"
                                    >{{ card.bets }} {{ card.bets === 1 ? 'bet' : 'bets' }}</span
                                >
                            </div>
                        </td>
                        <td class="text-no-wrap">PHP {{ totalBetAmount(card) }}</td>
                        <td>
                            <v-chip
                                :color="card.betting === 'Enabled' ? 'success' : 'default'"
                                variant="tonal"
                                size="small"
                                >{{ card.betting }}</v-chip
                            >
                        </td>
                    </tr>
                    <tr v-if="!cards.data.length">
                        <td :colspan="amount ? 5 : 6" class="pa-8 text-center text-medium-emphasis">
                            {{
                                search
                                    ? 'No cards match this card number.'
                                    : 'No cards created for this selection.'
                            }}
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="cards" selectable />
        </v-card>
        <v-dialog
            :model-value="!!selectedCard"
            max-width="900"
            scrollable
            @update:model-value="
                (value) => {
                    if (!value) selectedCard = null;
                }
            "
        >
            <v-card v-if="selectedCard">
                <v-card-title>Card {{ cardNumber(selectedCard.id) }} · Details</v-card-title>
                <v-card-text>
                    <div class="d-flex flex-wrap ga-2 mb-4">
                        <v-chip color="primary" variant="tonal" size="small"
                            >PHP {{ money(selectedCard.amount) }} ·
                            {{ betType(selectedCard.bet_type_id) }}</v-chip
                        >
                        <v-chip variant="tonal" size="small"
                            >Total bets: {{ selectedCard.bets }} · PHP
                            {{ totalBetAmount(selectedCard) }}</v-chip
                        >
                        <v-chip
                            :color="selectedCard.betting === 'Enabled' ? 'success' : 'default'"
                            variant="tonal"
                            size="small"
                            >Betting {{ selectedCard.betting.toLowerCase() }}</v-chip
                        >
                        <v-chip v-if="selectedCard.refunded === 'Yes'" color="warning" size="small"
                            >Refunded</v-chip
                        >
                    </div>
                    <v-progress-linear
                        v-if="loading"
                        indeterminate
                        aria-label="Loading card bets"
                    />
                    <v-table v-else density="compact" aria-label="Card bets">
                        <thead>
                            <tr>
                                <th>Combination</th>
                                <th>Quarter</th>
                                <th>Player / Ticket</th>
                                <th>Result</th>
                                <th>Refunded</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="bet in bets" :key="bet.id">
                                <td>
                                    <v-chip color="primary" variant="tonal" size="small">{{
                                        bet.combination
                                    }}</v-chip>
                                </td>
                                <td>{{ bet.quarter_id || 'All' }}</td>
                                <td>
                                    {{
                                        bet.player_name ||
                                        bet.name ||
                                        (bet.legacy_player_id
                                            ? `Legacy player #${bet.legacy_player_id}`
                                            : 'Not recorded')
                                    }}
                                </td>
                                <td>{{ bet.result || 'Pending' }}</td>
                                <td>{{ bet.refunded }}</td>
                            </tr>
                            <tr v-if="!bets.length">
                                <td colspan="5" class="pa-6 text-center text-medium-emphasis">
                                    No bets on this card.
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card-text>
                <v-card-actions
                    ><v-spacer /><v-btn @click="selectedCard = null">Close</v-btn></v-card-actions
                >
            </v-card>
        </v-dialog>
    </EndingShell>
</template>

<style scoped>
.ending-card-matchup {
    padding: 24px 16px;
    text-align: center;
}
.ending-card-teams {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    gap: 20px;
    max-width: 460px;
    margin: 20px auto;
}
.ending-card-team {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    overflow-wrap: anywhere;
}
.ending-card-bets {
    min-width: 150px;
    max-width: 320px;
    padding: 8px 0;
}
.ending-card-table :deep(th),
.ending-card-table :deep(td) {
    padding-inline: 20px;
}
</style>
