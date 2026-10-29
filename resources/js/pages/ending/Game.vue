<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import type { RouteDefinition } from '@/wayfinder';
import AdminLayout from '../../layouts/AdminLayout.vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import EndingLogs from '../../components/ending/EndingLogs.vue';
import GameForm from '../../components/ending/GameForm.vue';
import EntityLogo from '../../components/ending/EntityLogo.vue';
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
    cardThreshold: number;
    cardAmountFilter: number | null;
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
const subtractDialog = ref(false);
const subtractAmount = ref<BetAmount | null>(null);
const subtractForm = useForm({ quantity: 1 });
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
const busy = computed(
    () =>
        resultForm.processing ||
        cardForm.processing ||
        actionForm.processing ||
        subtractForm.processing,
);
const totalCards = computed(() =>
    props.amounts.reduce((total, amount) => total + Number(amount.cards), 0),
);
const generatedCards = computed(() =>
    props.amounts.reduce((total, amount) => total + Number(amount.generated_cards), 0),
);
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
function openCards(amount?: BetAmount) {
    clearErrors();
    cardForm.resetAndClearErrors();
    cardForm.bet_amount_id = amount?.id || null;
    cardDialog.value = true;
}
function viewCards(amount?: BetAmount) {
    tab.value = 'cards';
    router.get(
        EndingGameController.show.url(props.game.id),
        amount ? { bet_amount_id: amount.id } : {},
        {
            preserveState: true,
            preserveScroll: true,
            only: ['cards', 'cardAmountFilter'],
        },
    );
}
function openSubtract(amount: BetAmount) {
    subtractAmount.value = amount;
    subtractForm.resetAndClearErrors();
    subtractDialog.value = true;
}
function saveSubtraction() {
    if (!subtractAmount.value || busy.value) return;
    subtractForm.submit(
        EndingGameController.subtractCards({
            game: props.game.id,
            betAmount: subtractAmount.value.id,
        }),
        {
            preserveScroll: true,
            headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
            onSuccess: () => {
                subtractDialog.value = false;
            },
        },
    );
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
        ><div class="ending-page">
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
            <v-card class="mb-5 ending-game-overview">
                <v-card-text>
                    <div class="ending-overview-header">
                        <div class="ending-overview-identity">
                            <span class="ending-overview-league">{{
                                game.league || 'Ending Game'
                            }}</span>
                            <span class="ending-overview-id">Game #{{ game.id }}</span>
                        </div>
                        <v-btn
                            v-if="can(10) && scheduled"
                            variant="outlined"
                            color="secondary"
                            size="small"
                            prepend-icon="mdi-pencil-outline"
                            @click="edit = true"
                            >Edit Game</v-btn
                        >
                    </div>
                    <h1 class="ending-matchup-logos">
                        <span class="ending-matchup-team"
                            ><EntityLogo
                                :src="game.team_a_logo"
                                :name="game.team_a_name"
                                :size="84"
                            /><span class="d-block">{{
                                game.team_a_name || `Team #${game.team_a}`
                            }}</span></span
                        >
                        <span class="ending-versus text-subtitle-2">vs</span>
                        <span class="ending-matchup-team"
                            ><EntityLogo
                                :src="game.team_b_logo"
                                :name="game.team_b_name"
                                :size="84"
                            /><span class="d-block">{{
                                game.team_b_name || `Team #${game.team_b}`
                            }}</span></span
                        >
                    </h1>
                    <dl class="ending-game-facts" aria-label="Game details">
                        <div>
                            <dt><v-icon icon="mdi-calendar-clock-outline" size="16" />Schedule</dt>
                            <dd>{{ displayDate(game.schedule) }}</dd>
                        </div>
                        <div>
                            <dt><v-icon icon="mdi-basketball" size="16" />Game status</dt>
                            <dd>{{ game.status }}</dd>
                        </div>
                        <div>
                            <dt><v-icon icon="mdi-clock-outline" size="16" />Created at</dt>
                            <dd>{{ displayDate(game.created_at) }}</dd>
                        </div>
                    </dl>
                    <div class="ending-game-actions">
                        <section
                            class="ending-action-panel ending-action-panel--betting"
                            aria-labelledby="ending-betting-heading"
                        >
                            <div class="ending-action-heading">
                                <span class="ending-action-icon"
                                    ><v-icon icon="mdi-lock-outline" size="21"
                                /></span>
                                <h2 id="ending-betting-heading">Betting Control</h2>
                            </div>
                            <span
                                class="ending-action-status"
                                :class="
                                    game.betting === 'Enabled'
                                        ? 'ending-action-status--open'
                                        : 'ending-action-status--closed'
                                "
                            >
                                {{
                                    game.betting === 'Enabled' ? 'Accepting bets' : 'Betting closed'
                                }}
                            </span>
                            <p class="ending-action-description">
                                Control whether players can place bets on this game.
                            </p>
                            <v-btn
                                v-if="can(10)"
                                variant="flat"
                                :color="game.betting === 'Enabled' ? '#a85b00' : '#23834b'"
                                :prepend-icon="
                                    game.betting === 'Enabled'
                                        ? 'mdi-lock-outline'
                                        : 'mdi-lock-open-outline'
                                "
                                block
                                class="ending-action-button"
                                :disabled="busy || (!scheduled && game.betting !== 'Enabled')"
                                @click="confirmBetting"
                                >{{
                                    game.betting === 'Enabled' ? 'Close Betting' : 'Open Betting'
                                }}</v-btn
                            >
                            <p v-else class="ending-action-access">
                                You have view access to betting settings.
                            </p>
                        </section>
                        <section
                            class="ending-action-panel ending-action-panel--results"
                            aria-labelledby="ending-results-heading"
                        >
                            <div class="ending-action-heading">
                                <span class="ending-action-icon"
                                    ><v-icon icon="mdi-scoreboard-outline" size="21"
                                /></span>
                                <h2 id="ending-results-heading">Game Results</h2>
                            </div>
                            <span class="ending-action-status ending-action-status--results"
                                >{{ results.length }} of 4 quarters recorded</span
                            >
                            <p class="ending-action-description">
                                Enter official scores and record the winning combinations.
                            </p>
                            <v-btn
                                v-if="can(11)"
                                variant="flat"
                                color="#007fae"
                                prepend-icon="mdi-plus"
                                block
                                class="ending-action-button"
                                :disabled="busy || results.length >= 4"
                                @click="resultFormOpen()"
                                >Create Result</v-btn
                            >
                            <v-btn
                                v-else
                                variant="outlined"
                                color="primary"
                                prepend-icon="mdi-scoreboard-outline"
                                block
                                class="ending-action-button"
                                @click="tab = 'results'"
                                >View Results</v-btn
                            >
                        </section>
                        <section
                            class="ending-action-panel ending-action-panel--files"
                            aria-labelledby="ending-files-heading"
                        >
                            <div class="ending-action-heading">
                                <span class="ending-action-icon"
                                    ><v-icon icon="mdi-file-document-outline" size="21"
                                /></span>
                                <h2 id="ending-files-heading">Card Files</h2>
                            </div>
                            <span class="ending-action-status ending-action-status--closed"
                                >PDF generation unavailable</span
                            >
                            <div class="ending-file-progress">
                                <span
                                    >{{ generatedCards }} of {{ totalCards }} cards generated</span
                                >
                                <v-progress-linear
                                    :model-value="
                                        totalCards ? (generatedCards / totalCards) * 100 : 0
                                    "
                                    color="#a8b4c0"
                                    bg-color="#e8edf1"
                                    height="5"
                                    rounded
                                    aria-label="Generated card files"
                                />
                            </div>
                            <p class="ending-action-description">
                                PDF generation and email delivery are unavailable.
                            </p>
                        </section>
                    </div>
                </v-card-text>
            </v-card>
            <v-card class="mb-5">
                <div class="ending-toolbar">
                    <h2 class="text-caption text-primary">BET AMOUNTS</h2>
                    <div class="d-flex ga-2">
                        <v-btn
                            variant="outlined"
                            color="secondary"
                            prepend-icon="mdi-cards-outline"
                            size="small"
                            :href="EndingGameController.cardIndex.url(game.id)"
                            target="_blank"
                            rel="noopener noreferrer"
                            title="View all cards (opens in a new tab)"
                            >View All Cards</v-btn
                        ><v-btn
                            v-if="can(12)"
                            variant="flat"
                            color="primary"
                            prepend-icon="mdi-plus"
                            size="small"
                            :disabled="busy || !scheduled"
                            @click="openCards()"
                            >Create Bet</v-btn
                        >
                    </div>
                </div>
                <v-table class="ending-table" density="compact"
                    ><thead>
                        <tr>
                            <th>Amount</th>
                            <th>Bet type</th>
                            <th>Total cards</th>
                            <th>Subtracted cards</th>
                            <th>Cards exceeding {{ cardThreshold }} bets</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="amount in amounts" :key="amount.id">
                            <td class="text-no-wrap">PHP {{ money(amount.amount) }}</td>
                            <td>{{ betType(amount.bet_type_id) }}</td>
                            <td>{{ amount.cards }}</td>
                            <td>{{ amount.disabled_cards }}</td>
                            <td>{{ amount.exceeded_cards }}</td>
                            <td class="text-center text-no-wrap">
                                <v-btn
                                    v-if="can(12)"
                                    icon="mdi-plus-box-multiple-outline"
                                    color="success"
                                    variant="text"
                                    size="x-small"
                                    :aria-label="`Add ${betType(amount.bet_type_id)} ${money(amount.amount)} cards`"
                                    title="Add cards"
                                    :disabled="busy || !scheduled"
                                    @click="openCards(amount)"
                                />
                                <v-btn
                                    v-if="can(12)"
                                    icon="mdi-minus-box-multiple-outline"
                                    color="#a85b00"
                                    variant="text"
                                    size="x-small"
                                    :aria-label="`Subtract ${betType(amount.bet_type_id)} ${money(amount.amount)} cards`"
                                    title="Subtract unused cards"
                                    :disabled="busy || !scheduled"
                                    @click="openSubtract(amount)"
                                />
                                <v-btn
                                    icon="mdi-cards-outline"
                                    color="primary"
                                    variant="text"
                                    size="x-small"
                                    :aria-label="`View ${betType(amount.bet_type_id)} ${money(amount.amount)} cards`"
                                    title="View cards (opens in a new tab)"
                                    :href="
                                        EndingGameController.cardIndex.url(game.id, {
                                            query: { bet_amount_id: amount.id },
                                        })
                                    "
                                    target="_blank"
                                    rel="noopener noreferrer"
                                />
                            </td>
                        </tr>
                        <tr v-if="!amounts.length">
                            <td colspan="6" class="text-center pa-8">
                                No bet amounts yet. Create a bet to add cards.
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card>
            <v-card class="ending-table">
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
                    <div v-if="cardAmountFilter" class="d-flex align-center flex-wrap ga-2 pa-3">
                        <span class="text-caption"
                            >Showing cards for bet amount #{{ cardAmountFilter }}</span
                        ><v-btn size="small" variant="text" color="primary" @click="viewCards()"
                            >Clear amount filter</v-btn
                        >
                    </div>
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
            <v-dialog
                v-model="subtractDialog"
                max-width="440"
                :persistent="busy"
                class="ending-dialog"
            >
                <v-card
                    ><v-card-title>Subtract Cards</v-card-title
                    ><v-card-text>
                        <p v-if="subtractAmount" class="mb-4">
                            {{ betType(subtractAmount.bet_type_id) }} · PHP
                            {{ money(subtractAmount.amount) }}
                        </p>
                        <p class="text-caption text-medium-emphasis mb-4">
                            Only enabled cards without bets can be subtracted.
                        </p>
                        <v-alert
                            v-if="subtractForm.hasErrors"
                            type="error"
                            variant="tonal"
                            role="alert"
                            class="mb-4"
                            >{{ Object.values(subtractForm.errors).join(' ') }}</v-alert
                        >
                        <v-form id="ending-subtract-form" @submit.prevent="saveSubtraction"
                            ><v-text-field
                                v-model.number="subtractForm.quantity"
                                type="number"
                                label="Number of cards"
                                min="1"
                                max="1000"
                                step="1"
                                :disabled="busy"
                        /></v-form> </v-card-text
                    ><v-card-actions class="pa-4"
                        ><v-spacer /><v-btn :disabled="busy" @click="subtractDialog = false"
                            >Cancel</v-btn
                        ><v-btn
                            type="submit"
                            form="ending-subtract-form"
                            color="primary"
                            variant="flat"
                            :loading="busy"
                            >Subtract Cards</v-btn
                        ></v-card-actions
                    ></v-card
                >
            </v-dialog>
            <v-dialog
                v-model="resultDialog"
                max-width="640"
                scrollable
                :persistent="busy"
                aria-labelledby="ending-result-title"
                class="ending-dialog"
            >
                <v-card>
                    <v-card-title class="ending-result-header">
                        <div class="ending-result-heading">
                            <span class="ending-result-heading-icon"
                                ><v-icon icon="mdi-scoreboard-outline" size="22"
                            /></span>
                            <div>
                                <h2 id="ending-result-title">
                                    {{ resultForm.id ? 'Correct Result' : 'Create Result' }}
                                </h2>
                                <p>{{ game.league || 'Ending Game' }} · Game #{{ game.id }}</p>
                            </div>
                        </div>
                        <v-btn
                            icon="mdi-close"
                            variant="text"
                            size="small"
                            aria-label="Close result dialog"
                            :disabled="busy"
                            @click="resultDialog = false"
                        />
                    </v-card-title>
                    <v-divider />
                    <v-card-text>
                        <div class="ending-result-context">
                            <p>
                                {{
                                    resultForm.id
                                        ? 'Update the official scores for this quarter.'
                                        : 'Enter the official scores for this quarter.'
                                }}
                            </p>
                            <v-chip color="primary" size="small" variant="tonal"
                                >Quarter {{ resultForm.quarter_id }}</v-chip
                            >
                        </div>
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
                        >
                            <div class="ending-result-scoreboard">
                                <div class="ending-result-team">
                                    <EntityLogo
                                        :src="game.team_a_logo"
                                        :name="game.team_a_name"
                                        :size="56"
                                        aria-hidden="true"
                                    />
                                    <h3>{{ game.team_a_name || `Team #${game.team_a}` }}</h3>
                                    <label for="ending-team-a-score">Score</label>
                                    <v-text-field
                                        id="ending-team-a-score"
                                        v-model.number="resultForm.team_a_score"
                                        :aria-label="`${game.team_a_name} score`"
                                        :error-messages="resultForm.errors.team_a_score"
                                        type="number"
                                        inputmode="numeric"
                                        variant="outlined"
                                        min="0"
                                        max="9999"
                                        step="1"
                                        hide-details="auto"
                                    />
                                </div>
                                <span class="ending-result-versus" aria-hidden="true">vs</span>
                                <div class="ending-result-team">
                                    <EntityLogo
                                        :src="game.team_b_logo"
                                        :name="game.team_b_name"
                                        :size="56"
                                        aria-hidden="true"
                                    />
                                    <h3>{{ game.team_b_name || `Team #${game.team_b}` }}</h3>
                                    <label for="ending-team-b-score">Score</label>
                                    <v-text-field
                                        id="ending-team-b-score"
                                        v-model.number="resultForm.team_b_score"
                                        :aria-label="`${game.team_b_name} score`"
                                        :error-messages="resultForm.errors.team_b_score"
                                        type="number"
                                        inputmode="numeric"
                                        variant="outlined"
                                        min="0"
                                        max="9999"
                                        step="1"
                                        hide-details="auto"
                                    />
                                </div>
                            </div>
                            <div class="ending-result-preview">
                                <div class="ending-result-preview-label">Winning combination</div>
                                <output
                                    class="ending-result-combination"
                                    aria-live="polite"
                                    aria-label="Winning combination"
                                    >{{ Number(resultForm.team_a_score) % 10 }}-{{
                                        Number(resultForm.team_b_score) % 10
                                    }}</output
                                >
                                <p>The last digit of each score, in team order.</p>
                            </div>
                            <v-textarea
                                v-if="resultForm.id"
                                v-model="resultForm.reason"
                                label="Reason for correction"
                                variant="outlined"
                                :error-messages="resultForm.errors.reason"
                                rows="2"
                                class="mt-5"
                                required
                            />
                        </v-form>
                    </v-card-text>
                    <v-divider />
                    <v-card-actions class="ending-result-actions">
                        <v-spacer /><v-btn
                            variant="outlined"
                            :disabled="busy"
                            @click="resultDialog = false"
                            >Cancel</v-btn
                        ><v-btn
                            type="submit"
                            form="ending-result-form"
                            color="primary"
                            variant="flat"
                            prepend-icon="mdi-check"
                            :loading="busy"
                            >Save Result</v-btn
                        ></v-card-actions
                    ></v-card
                >
            </v-dialog>
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
.ending-overview-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.ending-overview-identity {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.ending-overview-league {
    font-size: 14px;
    font-weight: 600;
}
.ending-overview-id {
    padding-left: 10px;
    border-left: 1px solid #dce2e7;
    color: #757575;
    font-size: 12px;
}
.ending-game-facts {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    margin: 0 0 20px;
    padding: 18px 0;
    border-top: 1px solid #e8edf1;
    border-bottom: 1px solid #e8edf1;
}
.ending-game-facts dt {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 7px;
    color: #7b8590;
    font-size: 11px;
}
.ending-game-facts dd {
    margin: 0;
    font-size: 12px;
    font-weight: 500;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.ending-game-actions {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}
.ending-action-panel {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    min-width: 0;
    padding: 18px;
    border: 1px solid #e3e8ed;
    border-top-width: 3px;
    border-radius: 8px;
}
.ending-action-panel--betting {
    border-top-color: #d99b45;
    background: #fffdf8;
}
.ending-action-panel--results {
    border-top-color: #00b6fe;
    background: #f8fcff;
}
.ending-action-panel--files {
    border-top-color: #b8c3ce;
    background: #f8f9fb;
}
.ending-action-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
}
.ending-action-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 8px;
}
.ending-action-panel--betting .ending-action-icon {
    color: #a85b00;
    background: #fff0d8;
}
.ending-action-panel--results .ending-action-icon {
    color: #007fae;
    background: #e4f5ff;
}
.ending-action-panel--files .ending-action-icon {
    color: #748291;
    background: #e9edf2;
}
.ending-action-heading h2 {
    margin: 0;
    color: #263441;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.4;
}
.ending-action-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 9px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 500;
    line-height: 1.5;
}
.ending-action-status--open {
    color: #247345;
    background: #e7f4eb;
}
.ending-action-status--closed {
    color: #647180;
    background: #e9edf2;
}
.ending-action-status--results {
    color: #126f98;
    background: #e4f3fb;
}
.ending-action-description {
    margin: 12px 0 18px;
    color: #6b7784;
    font-size: 12px;
    line-height: 1.7;
}
.ending-action-button {
    margin-top: auto;
    min-height: 40px;
    height: auto !important;
}
.ending-action-button :deep(.v-btn__content) {
    padding-block: 10px;
    white-space: normal;
    font-weight: 500;
}
.ending-action-access {
    margin: auto 0 0;
    color: #84909c;
    font-size: 11px;
    line-height: 1.6;
}
.ending-file-progress {
    display: grid;
    gap: 10px;
    align-self: stretch;
    margin-top: 16px;
    color: #5d6b79;
    font-size: 12px;
}
.ending-action-panel--files .ending-action-description {
    margin-bottom: 0;
    font-size: 11px;
}
@media (max-width: 959px) {
    .ending-game-actions {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .ending-action-panel--files {
        grid-column: 1 / -1;
    }
}
.ending-result-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.ending-result-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.ending-result-heading-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    border-radius: 10px;
    color: rgb(var(--v-theme-primary));
    background: #edfaff;
}
.ending-result-heading h2 {
    margin: 0;
    font-size: 18px;
    line-height: 1.4;
}
.ending-result-heading p {
    margin: 4px 0 0;
    color: #757575;
    font-size: 11px;
    line-height: 1.5;
}
.ending-result-context {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 20px;
}
.ending-result-context p {
    margin: 0;
    color: #757575;
    font-size: 12px;
    line-height: 1.6;
}
.ending-result-context .v-chip {
    flex-shrink: 0;
    font-size: 11px;
}
.ending-result-scoreboard {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 24px minmax(0, 1fr);
    align-items: center;
    gap: 12px;
}
.ending-result-team {
    min-width: 0;
    padding: 20px;
    border: 1px solid #e3e8ec;
    border-radius: 8px;
    background: #fafbfc;
    text-align: center;
}
.ending-result-team h3 {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    margin: 10px 0 12px;
    font-size: 13px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}
.ending-result-team label {
    display: block;
    margin-bottom: 8px;
    color: #757575;
    font-size: 11px;
}
.ending-result-team :deep(.v-field) {
    background: white;
}
.ending-result-team :deep(.v-field__input) {
    min-height: 64px;
    padding: 10px 8px;
    font-size: 30px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    text-align: center;
}
.ending-result-versus {
    color: #9e9e9e;
    font-size: 12px;
    text-align: center;
}
.ending-result-preview {
    margin-top: 20px;
    padding: 16px;
    border: 1px solid #c6ebfa;
    border-radius: 8px;
    background: #f2fbff;
    text-align: center;
}
.ending-result-preview-label {
    color: #467588;
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 1px;
    text-transform: uppercase;
}
.ending-result-combination {
    display: block;
    margin: 6px 0;
    color: #0085ba;
    font-size: 34px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    letter-spacing: 4px;
    line-height: 1.4;
}
.ending-result-preview p {
    margin: 0;
    color: #65818d;
    font-size: 11px;
    line-height: 1.5;
}
.ending-result-actions {
    gap: 8px;
    padding: 16px 20px;
}
.ending-result-actions > .v-btn {
    margin: 0;
    padding-inline: 20px;
}
@media (max-width: 599px) {
    .ending-game-facts {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    .ending-game-actions {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .ending-result-heading {
        gap: 10px;
    }
    .ending-result-heading h2 {
        font-size: 16px;
    }
    .ending-result-context {
        align-items: flex-start;
    }
    .ending-result-scoreboard {
        grid-template-columns: minmax(0, 1fr) 18px minmax(0, 1fr);
        gap: 8px;
    }
    .ending-result-team {
        padding: 16px 10px;
    }
    .ending-result-team h3 {
        font-size: 12px;
    }
    .ending-result-team :deep(.v-field__input) {
        min-height: 56px;
        font-size: 26px;
    }
    .ending-result-actions > .v-spacer {
        display: none;
    }
    .ending-result-actions > .v-btn {
        flex: 1;
        padding-inline: 12px;
    }
}
</style>
