<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import type { RouteDefinition } from '@/wayfinder';
import AdminLayout from '../../layouts/AdminLayout.vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import EndingLogs from '../../components/ending/EndingLogs.vue';
import GameForm from '../../components/ending/GameForm.vue';
import type { AdminPageProps } from '../../types';
import type {
    EndingGame,
    GameResult,
    Winner,
    GameCard,
    Bet,
    BetAmount,
    Prize,
    EndingLog,
    League,
    Team,
    Preset,
    PageResult,
} from '../../types/ending';
import { displayDate, money, betType } from '../../types/ending';

const props = defineProps<{
    game: EndingGame;
    results: GameResult[];
    winners: PageResult<Winner> | null;
    cards: PageResult<GameCard>;
    amounts: BetAmount[];
    prizes: Prize[];
    logs: PageResult<EndingLog>;
    legacyLogs: PageResult<EndingLog>;
    leagues: League[];
    teams: Team[];
    presets: Preset[];
    cardBets?: Bet[];
}>();
const page = usePage<AdminPageProps>();
const can = (id: number) => page.props.auth.user?.permissions.some((p) => p.id === id) || false;
const tab = ref('results');
const edit = ref(false);
const message = computed(() => (typeof page.flash.success === 'string' ? page.flash.success : ''));
const dismissedMessage = ref('');
const resultDialog = ref(false);
const resultForm = useForm({
    id: null as number | null,
    quarter_id: 1,
    team_a_score: 0,
    team_b_score: 0,
    reason: '',
});
const cardDialog = ref(false);
const cardForm = useForm({
    bet_amount_id: null as number | null,
    quantity: 1,
    bet_type_id: 2,
    amount: '15.00',
    prizes: [1, 2, 3, 4].map((q) => ({
        quarter_id: q,
        is_cash: 'Cash',
        cash_prize: '0.00',
        other_prize: '',
    })),
});
const actionForm = useForm({
    enabled: false,
    bet_id: null as number | null,
    quarter_id: null as number | null,
});
const busy = computed(() => resultForm.processing || cardForm.processing || actionForm.processing);
const error = computed(() =>
    Object.values({ ...resultForm.errors, ...cardForm.errors, ...actionForm.errors })
        .flat()
        .join(' '),
);
const scheduled = computed(() => !props.game.game_status_id && !props.results.length);
const confirmation = ref<{
    target: RouteDefinition<'post' | 'put'>;
    title: string;
    detail: string;
} | null>(null);
const selectedCard = ref<GameCard | null>(null);
const bets = computed(() => props.cardBets || []);
const betLoading = ref(false);
const amountOptions = computed(() => [
    { title: 'New amount and prizes', value: null },
    ...props.amounts.map((a) => ({
        title: '#' + a.id + ' · ' + betType(a.bet_type_id) + ' · ' + money(a.amount),
        value: a.id,
    })),
]);
function clearErrors() {
    resultForm.clearErrors();
    cardForm.clearErrors();
    actionForm.clearErrors();
}
function resultFormOpen(result?: GameResult) {
    clearErrors();
    resultForm.defaults({
        id: result?.id || null,
        quarter_id: result?.quarter_id || props.results.length + 1,
        team_a_score: result?.team_a_score || 0,
        team_b_score: result?.team_b_score || 0,
        reason: '',
    });
    resultForm.reset();
    resultDialog.value = true;
}
function saveResult() {
    if (busy.value) return;
    resultForm.submit(
        resultForm.id
            ? EndingGameController.updateResult({ game: props.game.id, result: resultForm.id })
            : EndingGameController.result(props.game.id),
        {
            preserveScroll: true,
            headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
            onSuccess: () => {
                resultDialog.value = false;
                dismissedMessage.value = '';
            },
        },
    );
}
function saveCards() {
    if (busy.value) return;
    cardForm.transform((data) =>
        data.bet_amount_id ? { bet_amount_id: data.bet_amount_id, quantity: data.quantity } : data,
    );
    cardForm.submit(EndingGameController.cards(props.game.id), {
        preserveScroll: true,
        headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
        onSuccess: () => {
            cardDialog.value = false;
            dismissedMessage.value = '';
        },
    });
}
function confirmWinner(winner: Winner, action: 'verify' | 'unverify' | 'approve') {
    clearErrors();
    actionForm.bet_id = winner.bet_id;
    actionForm.quarter_id = winner.quarter_id;
    const prize =
        winner.is_cash === 'Cash'
            ? money(winner.cash_prize) + ' tokens'
            : winner.other_prize || 'Non-cash prize';
    confirmation.value = {
        target: EndingGameController.winner({ game: props.game.id, action }),
        title:
            action === 'approve'
                ? 'Approve & Declare Winner'
                : action === 'verify'
                  ? 'Verify Winner'
                  : 'Unverify Winner',
        detail:
            'Quarter ' +
            winner.quarter_id +
            ' · Card #' +
            winner.card_id +
            ' · ' +
            winner.combination +
            ' · ' +
            (winner.player_name || winner.ticket_name || 'Player #' + winner.player_id) +
            ' · ' +
            prize +
            '.' +
            (action === 'approve'
                ? ' This declares the winner. Prize release is recorded separately.'
                : ''),
    };
}
function confirmBetting() {
    clearErrors();
    actionForm.enabled = props.game.betting !== 'Enabled';
    actionForm.bet_id = null;
    actionForm.quarter_id = null;
    confirmation.value = {
        target: EndingGameController.betting(props.game.id),
        title: props.game.betting === 'Enabled' ? 'Close Betting' : 'Open Betting',
        detail: 'Change betting for game #' + props.game.id + '?',
    };
}
function submitAction(target: RouteDefinition<'post' | 'put'>, onSuccess?: () => void) {
    if (busy.value) return;
    actionForm.submit(target, {
        preserveScroll: true,
        headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
        onSuccess: () => {
            dismissedMessage.value = '';
            onSuccess?.();
        },
    });
}
function confirm() {
    if (confirmation.value)
        submitAction(confirmation.value.target, () => {
            confirmation.value = null;
        });
}
function toggleCard(card: GameCard) {
    clearErrors();
    actionForm.enabled = card.betting !== 'Enabled';
    actionForm.bet_id = null;
    actionForm.quarter_id = null;
    submitAction(EndingGameController.cardBetting({ game: props.game.id, card: card.id }));
}
function viewCard(card: GameCard) {
    selectedCard.value = card;
    betLoading.value = true;
    router.reload({
        only: ['cardBets'],
        data: { card_id: card.id },
        onFinish: () => {
            betLoading.value = false;
        },
    });
}
</script>
<template>
    <Head :title="`Ending Game #${game.id}`" />
    <AdminLayout
        ><div class="pa-4 pa-md-6">
            <v-btn
                variant="text"
                prepend-icon="mdi-arrow-left"
                class="mb-3"
                @click="router.visit(EndingGameController.index())"
                >Scheduled Games</v-btn
            >
            <v-alert
                v-if="error && !resultDialog && !cardDialog && !confirmation"
                type="error"
                variant="tonal"
                class="mb-4"
                role="alert"
                >{{ error }}</v-alert
            >
            <v-alert
                v-if="message && message !== dismissedMessage"
                type="success"
                variant="tonal"
                closable
                class="mb-4"
                @click:close="dismissedMessage = message"
                >{{ message }}</v-alert
            >
            <v-card class="mb-5">
                <v-card-text>
                    <div class="d-flex align-center flex-wrap ga-2 mb-3">
                        <div class="text-subtitle-1 flex-grow-1 text-center">
                            {{ game.league }} · Game #{{ game.id }}
                        </div>
                        <v-btn
                            v-if="can(10) && scheduled"
                            variant="text"
                            color="primary"
                            prepend-icon="mdi-pencil"
                            @click="edit = true"
                            >Edit</v-btn
                        >
                    </div>
                    <h1 class="text-h5 text-center font-weight-medium mb-5 ending-matchup">
                        {{ game.team_a_name || `Team #${game.team_a}` }}
                        <span class="text-medium-emphasis text-subtitle-1 mx-2">vs</span>
                        {{ game.team_b_name || `Team #${game.team_b}` }}
                    </h1>
                    <v-row
                        ><v-col cols="12" md="4"
                            ><div class="text-caption text-primary mb-2">GAME DETAILS</div>
                            <p class="mb-2">{{ displayDate(game.schedule) }} (Philippines)</p>
                            <v-chip size="small" class="mr-2">{{ game.status }}</v-chip
                            ><v-chip
                                size="small"
                                :color="game.betting === 'Enabled' ? 'success' : 'default'"
                                >Betting {{ game.betting }}</v-chip
                            ></v-col
                        >
                        <v-col cols="12" md="4"
                            ><div class="text-caption text-primary mb-2">SETTINGS</div>
                            <v-btn
                                v-if="can(10)"
                                variant="outlined"
                                color="primary"
                                :disabled="busy || (!scheduled && game.betting !== 'Enabled')"
                                @click="confirmBetting"
                                >{{
                                    game.betting === 'Enabled' ? 'Close Betting' : 'Open Betting'
                                }}</v-btn
                            >
                            <p v-else class="text-medium-emphasis">View access</p></v-col
                        >
                        <v-col cols="12" md="4"
                            ><div class="text-caption text-primary mb-2">GAME ACTIONS</div>
                            <div class="d-flex flex-wrap ga-2">
                                <v-btn
                                    v-if="can(11)"
                                    variant="outlined"
                                    color="primary"
                                    :disabled="busy || results.length >= 4"
                                    @click="resultFormOpen()"
                                    >Create Result</v-btn
                                ><v-btn
                                    v-if="can(12)"
                                    variant="outlined"
                                    color="primary"
                                    :disabled="busy || !scheduled"
                                    @click="
                                        clearErrors();
                                        cardDialog = true;
                                    "
                                    >Create Cards</v-btn
                                >
                            </div></v-col
                        >
                    </v-row>
                </v-card-text>
            </v-card>
            <v-card>
                <v-tabs v-model="tab" color="primary" show-arrows
                    ><v-tab value="results">Results</v-tab
                    ><v-tab value="cards">Cards ({{ cards.total }})</v-tab
                    ><v-tab v-if="winners" value="winners">Winners ({{ winners.total }})</v-tab
                    ><v-tab value="logs">Activity</v-tab
                    ><v-tab value="legacy">Historical Logs</v-tab></v-tabs
                ><v-divider />
                <div v-if="tab === 'results'">
                    <p class="pa-4 text-body-2 text-medium-emphasis">
                        Enter the official scores for each quarter. The last digit of each score
                        determines the winning combination.
                    </p>
                    <v-table
                        ><thead>
                            <tr>
                                <th>Quarter</th>
                                <th>{{ game.team_a_name }}</th>
                                <th>{{ game.team_b_name }}</th>
                                <th>Combination</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="result in results" :key="result.id">
                                <td>{{ result.quarter_id }}</td>
                                <td>{{ result.team_a_score }}</td>
                                <td>{{ result.team_b_score }}</td>
                                <td>
                                    <v-chip color="primary" size="small">{{
                                        result.combination
                                    }}</v-chip>
                                </td>
                                <td>
                                    <v-btn
                                        v-if="can(11)"
                                        variant="text"
                                        size="small"
                                        color="primary"
                                        :disabled="busy"
                                        @click="resultFormOpen(result)"
                                        >Correct Result</v-btn
                                    >
                                </td>
                            </tr>
                            <tr v-if="!results.length">
                                <td colspan="5" class="pa-8 text-center text-medium-emphasis">
                                    No results yet.
                                </td>
                            </tr>
                        </tbody></v-table
                    >
                </div>
                <div v-if="tab === 'cards'">
                    <v-expansion-panels variant="accordion" class="pa-3"
                        ><v-expansion-panel title="Bet amounts and prizes"
                            ><v-expansion-panel-text
                                ><div v-for="amount in amounts" :key="amount.id" class="mb-3">
                                    <strong
                                        >{{ betType(amount.bet_type_id) }} ·
                                        {{ money(amount.amount) }} tokens ·
                                        {{ amount.cards }} cards</strong
                                    >
                                    <div class="d-flex flex-wrap ga-3 text-caption">
                                        <span
                                            v-for="prize in prizes.filter(
                                                (p) => p.bet_amount_id === amount.id,
                                            )"
                                            :key="prize.quarter_id"
                                            >Q{{ prize.quarter_id }}:
                                            {{
                                                prize.is_cash === 'Cash'
                                                    ? `${money(prize.cash_prize)} tokens`
                                                    : prize.other_prize
                                            }}</span
                                        >
                                    </div>
                                </div></v-expansion-panel-text
                            ></v-expansion-panel
                        ></v-expansion-panels
                    >
                    <v-table
                        ><thead>
                            <tr>
                                <th>Card</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Bets</th>
                                <th>Betting</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="card in cards.data" :key="card.id">
                                <td>#{{ String(card.id).padStart(6, '0') }}</td>
                                <td>{{ betType(card.bet_type_id) }}</td>
                                <td>{{ money(card.amount) }}</td>
                                <td>{{ card.bets }}</td>
                                <td>{{ card.betting }}</td>
                                <td class="text-no-wrap">
                                    <v-btn
                                        variant="text"
                                        size="small"
                                        color="primary"
                                        @click="viewCard(card)"
                                        >View Bets</v-btn
                                    ><v-btn
                                        v-if="can(12) && scheduled && !card.bets"
                                        variant="text"
                                        size="small"
                                        :disabled="busy"
                                        @click="toggleCard(card)"
                                        >{{
                                            card.betting === 'Enabled' ? 'Disable' : 'Enable'
                                        }}</v-btn
                                    >
                                </td>
                            </tr>
                            <tr v-if="!cards.data.length">
                                <td colspan="6" class="pa-8 text-center text-medium-emphasis">
                                    No cards created.
                                </td>
                            </tr>
                        </tbody></v-table
                    ><EndingPager :page="cards" />
                </div>
                <div v-if="tab === 'winners' && winners">
                    <p class="pa-4 text-body-2 text-medium-emphasis">
                        Winning bets appear after a result is entered. Verify each winner, then
                        approve to declare. Prize release is tracked separately.
                    </p>
                    <v-table
                        ><thead>
                            <tr>
                                <th>Quarter / Card</th>
                                <th>Player</th>
                                <th>Combination</th>
                                <th>Prize</th>
                                <th>Status</th>
                                <th>Released</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(winner, index) in winners.data"
                                :key="`${winner.bet_id}-${winner.quarter_id}-${index}`"
                            >
                                <td class="text-no-wrap">
                                    Q{{ winner.quarter_id }} · #{{ winner.card_id }}
                                </td>
                                <td>
                                    {{
                                        winner.player_name ||
                                        winner.ticket_name ||
                                        `Legacy player #${winner.legacy_player_id}`
                                    }}
                                    <div v-if="!winner.player_id" class="text-caption text-warning">
                                        Identity needs review
                                    </div>
                                    <div v-if="!winner.eligible" class="text-caption text-warning">
                                        Historical record · review combination
                                    </div>
                                </td>
                                <td>{{ winner.combination }}</td>
                                <td>
                                    {{
                                        winner.is_cash === 'Cash'
                                            ? `${money(winner.cash_prize)} tokens`
                                            : winner.other_prize
                                    }}
                                </td>
                                <td>
                                    <v-chip
                                        size="small"
                                        :color="
                                            winner.status === 'Approved'
                                                ? 'success'
                                                : winner.status === 'Verified'
                                                  ? 'primary'
                                                  : 'default'
                                        "
                                        >{{ winner.status }}</v-chip
                                    >
                                </td>
                                <td>{{ winner.prize_released || 'No' }}</td>
                                <td class="text-no-wrap">
                                    <v-btn
                                        v-if="!winner.winner_id && can(8)"
                                        variant="text"
                                        color="primary"
                                        size="small"
                                        :disabled="busy || !winner.player_id || !winner.eligible"
                                        @click="confirmWinner(winner, 'verify')"
                                        >Verify</v-btn
                                    ><template v-if="winner.winner_status_id === 1"
                                        ><v-btn
                                            v-if="can(9)"
                                            variant="text"
                                            color="success"
                                            size="small"
                                            :disabled="
                                                busy || !winner.player_id || !winner.eligible
                                            "
                                            @click="confirmWinner(winner, 'approve')"
                                            >Approve & Declare</v-btn
                                        ><v-btn
                                            v-if="can(8) && winner.prize_released !== 'Yes'"
                                            variant="text"
                                            size="small"
                                            :disabled="busy"
                                            @click="confirmWinner(winner, 'unverify')"
                                            >Unverify</v-btn
                                        ></template
                                    >
                                </td>
                            </tr>
                            <tr v-if="!winners.data.length">
                                <td colspan="7" class="pa-8 text-center text-medium-emphasis">
                                    No winning bets found.
                                </td>
                            </tr>
                        </tbody></v-table
                    ><EndingPager :page="winners" />
                </div>
                <EndingLogs v-if="tab === 'logs'" :logs="logs" /><EndingLogs
                    v-if="tab === 'legacy'"
                    :logs="legacyLogs"
                />
            </v-card>
            <GameForm
                v-model="edit"
                :game="game"
                :leagues="leagues"
                :teams="teams"
                :presets="presets"
            />
            <v-dialog v-model="resultDialog" max-width="550" :persistent="busy"
                ><v-card
                    ><v-card-title
                        >{{ resultForm.id ? 'Correct Result' : 'Create Result' }} · Quarter
                        {{ resultForm.quarter_id }}</v-card-title
                    ><v-card-text>
                        <v-alert
                            v-if="error"
                            type="error"
                            variant="tonal"
                            class="mb-4"
                            role="alert"
                            >{{ error }}</v-alert
                        >
                        <v-form
                            id="ending-result-form"
                            :disabled="busy"
                            @submit.prevent="saveResult"
                            ><v-text-field
                                v-model.number="resultForm.team_a_score"
                                :label="`${game.team_a_name} score`"
                                type="number"
                                min="0"
                                max="9999" /><v-text-field
                                v-model.number="resultForm.team_b_score"
                                :label="`${game.team_b_name} score`"
                                type="number"
                                min="0"
                                max="9999" />
                            <p class="mb-4">
                                Winning combination:
                                <strong
                                    >{{ Number(resultForm.team_a_score) % 10 }}-{{
                                        Number(resultForm.team_b_score) % 10
                                    }}</strong
                                >
                            </p>
                            <v-textarea
                                v-if="resultForm.id"
                                v-model="resultForm.reason"
                                label="Reason for correction"
                                rows="2"
                                required
                        /></v-form> </v-card-text
                    ><v-card-actions
                        ><v-spacer /><v-btn :disabled="busy" @click="resultDialog = false"
                            >Cancel</v-btn
                        ><v-btn
                            type="submit"
                            form="ending-result-form"
                            color="primary"
                            :loading="busy"
                            >Save Result</v-btn
                        ></v-card-actions
                    ></v-card
                ></v-dialog
            >
            <v-dialog v-model="cardDialog" max-width="750" scrollable :persistent="busy"
                ><v-card
                    ><v-card-title>Create Cards</v-card-title
                    ><v-card-text>
                        <v-alert
                            v-if="error"
                            type="error"
                            variant="tonal"
                            class="mb-4"
                            role="alert"
                            >{{ error }}</v-alert
                        >
                        <v-form id="ending-card-form" :disabled="busy" @submit.prevent="saveCards"
                            ><v-select
                                v-model="cardForm.bet_amount_id"
                                :items="amountOptions"
                                label="Bet amount"
                            /><v-text-field
                                v-model.number="cardForm.quantity"
                                type="number"
                                min="1"
                                max="1000"
                                label="Number of cards"
                            />
                            <template v-if="!cardForm.bet_amount_id"
                                ><v-row
                                    ><v-col cols="12" sm="6"
                                        ><v-select
                                            v-model="cardForm.bet_type_id"
                                            :items="[
                                                { title: 'Traditional', value: 1 },
                                                { title: 'Per Quarter', value: 2 },
                                            ]"
                                            label="Bet type" /></v-col
                                    ><v-col cols="12" sm="6"
                                        ><v-text-field
                                            v-model="cardForm.amount"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            label="Bet amount (tokens)" /></v-col
                                ></v-row>
                                <h3 class="text-subtitle-1 mb-3">Prizes by quarter</h3>
                                <v-row
                                    v-for="prize in cardForm.prizes"
                                    :key="prize.quarter_id"
                                    dense
                                    ><v-col cols="12" sm="5"
                                        ><v-select
                                            v-model="prize.is_cash"
                                            :items="['Cash', 'Not Cash']"
                                            :label="`Quarter ${prize.quarter_id} prize type`" /></v-col
                                    ><v-col cols="12" sm="7"
                                        ><v-text-field
                                            v-if="prize.is_cash === 'Cash'"
                                            v-model="prize.cash_prize"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :label="`Quarter ${prize.quarter_id} tokens`" /><v-text-field
                                            v-else
                                            v-model="prize.other_prize"
                                            :label="`Quarter ${prize.quarter_id} prize description`" /></v-col></v-row
                            ></template>
                        </v-form> </v-card-text
                    ><v-card-actions
                        ><v-spacer /><v-btn :disabled="busy" @click="cardDialog = false"
                            >Cancel</v-btn
                        ><v-btn
                            type="submit"
                            form="ending-card-form"
                            color="primary"
                            :loading="busy"
                            >Create Cards</v-btn
                        ></v-card-actions
                    ></v-card
                ></v-dialog
            >
            <v-dialog
                :model-value="!!confirmation"
                max-width="550"
                :persistent="busy"
                @update:model-value="
                    (value) => {
                        if (!value) confirmation = null;
                    }
                "
                ><v-card v-if="confirmation"
                    ><v-card-title>{{ confirmation.title }}</v-card-title
                    ><v-card-text
                        ><v-alert
                            v-if="error"
                            type="error"
                            variant="tonal"
                            class="mb-3"
                            role="alert"
                            >{{ error }}</v-alert
                        >{{ confirmation.detail }}</v-card-text
                    ><v-card-actions
                        ><v-spacer /><v-btn :disabled="busy" @click="confirmation = null"
                            >Cancel</v-btn
                        ><v-btn color="primary" :loading="busy" @click="confirm"
                            >Confirm</v-btn
                        ></v-card-actions
                    ></v-card
                ></v-dialog
            >
            <v-dialog
                :model-value="!!selectedCard"
                max-width="900"
                scrollable
                @update:model-value="
                    (value) => {
                        if (!value) selectedCard = null;
                    }
                "
                ><v-card
                    ><v-card-title>Card #{{ selectedCard?.id }} · Bets</v-card-title
                    ><v-progress-linear v-if="betLoading" indeterminate /><v-card-text
                        ><v-table
                            ><thead>
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
                                    <td>{{ bet.combination }}</td>
                                    <td>{{ bet.quarter_id || 'All' }}</td>
                                    <td>
                                        {{
                                            bet.player_name ||
                                            bet.name ||
                                            `Legacy player #${bet.legacy_player_id}`
                                        }}
                                    </td>
                                    <td>{{ bet.result }}</td>
                                    <td>{{ bet.refunded }}</td>
                                </tr>
                                <tr v-if="!betLoading && !bets.length">
                                    <td colspan="5" class="pa-5 text-center">
                                        No bets on this card.
                                    </td>
                                </tr>
                            </tbody></v-table
                        ></v-card-text
                    ><v-card-actions
                        ><v-spacer /><v-btn @click="selectedCard = null"
                            >Close</v-btn
                        ></v-card-actions
                    ></v-card
                ></v-dialog
            >
        </div></AdminLayout
    >
</template>
<style scoped>
.ending-matchup {
    overflow-wrap: anywhere;
    line-height: 1.6;
}
:deep(.v-table) {
    max-width: 100%;
}
:deep(.v-card-title) {
    white-space: normal;
}
</style>
