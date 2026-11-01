<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { index, bets, refresh, winner } from '@/actions/App/Http/Controllers/LuckyFourController';
import { show as endingShow } from '@/actions/App/Http/Controllers/EndingGameController';
import LuckyFourShell from '@/components/lucky-four/LuckyFourShell.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import EntityLogo from '@/components/ending/EntityLogo.vue';
import { usePermissions } from '@/composables/usePermissions';
import { displayDate, money } from '@/types/ending';
import type { EndingGame, PageResult } from '@/types/ending';
import type { LuckyFourBet, LuckyFourPrizes } from '@/types/lucky-four';
import type { AdminPageProps } from '@/types';
const props = defineProps<{
    game: EndingGame;
    prizes: LuckyFourPrizes | null;
    results: {
        id: number;
        quarter_id: number;
        team_a_score: number;
        team_b_score: number;
        lucky_four_result: number | null;
    }[];
    combination: string | null;
    totals: { bets: number; amount: string | number; pending: number; released: string | number };
    winners: PageResult<LuckyFourBet>;
    audits: { id: number; action: string; actor: string | null; created_at: string }[];
    legacyLogs: { id: number; action: string; details: string | null; created_at: string | null }[];
    migrationReady: boolean;
}>();
const page = usePage<AdminPageProps>();
const { can } = usePermissions();
const form = useForm({ ending_bet_id: 0 });
const refreshForm = useForm({});
const confirmation = ref<{ action: 'verify' | 'unverify' | 'approve'; bet: LuckyFourBet } | null>(
    null,
);
const tab = ref('winners');
const finished = computed(
    () =>
        props.game.game_status_id === 5 &&
        props.game.betting === 'Disabled' &&
        props.combination !== null,
);
const quarters = computed(() =>
    [1, 2, 3, 4].map((quarter) => {
        const result = props.results.find((result) => result.quarter_id === quarter);
        const digit = result?.lucky_four_result;
        return { quarter, result, digit, recorded: digit !== null && digit !== undefined };
    }),
);
const recordedQuarters = computed(
    () => quarters.value.filter((quarter) => quarter.recorded).length,
);
const bettingOpen = computed(() => props.game.betting === 'Enabled');
const refreshHint = computed(() => {
    if (!props.migrationReady) return 'Refresh becomes available after history is verified.';
    if (!props.totals.pending) return 'The jackpot is up to date. No pending bets.';
    if (bettingOpen.value) return 'Close betting before adding pending bets to the jackpot.';
    return `${props.totals.pending.toLocaleString()} pending bets are ready to add to the jackpot.`;
});
const contribution = computed(() =>
    props.prizes?.percentage_deduction == null
        ? '—'
        : Number(props.prizes.percentage_deduction).toLocaleString('en-PH', {
              maximumFractionDigits: 2,
          }) + '%',
);
function confirm(bet: LuckyFourBet, action: 'verify' | 'unverify' | 'approve') {
    form.clearErrors();
    form.ending_bet_id = bet.ending_bet_id;
    confirmation.value = { action, bet };
}
function submit() {
    if (!confirmation.value) return;
    form.submit(winner({ game: props.game.id, action: confirmation.value.action }), {
        preserveScroll: true,
        headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
        onSuccess: () => {
            confirmation.value = null;
        },
    });
}
function refreshPool() {
    refreshForm.submit(refresh(props.game.id), {
        preserveScroll: true,
        headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
    });
}
</script>
<template>
    <LuckyFourShell :title="'Game #' + game.id" :migration-ready="migrationReady">
        <template #actions>
            <v-btn
                class="game-action"
                variant="text"
                prepend-icon="mdi-arrow-left"
                @click="router.visit(index())"
                >All games</v-btn
            ><v-btn
                v-if="can('Ending Games')"
                class="game-action"
                variant="outlined"
                prepend-icon="mdi-basketball"
                @click="router.visit(endingShow(game.id))"
                >Ending results</v-btn
            ><v-btn
                class="game-action"
                color="primary"
                variant="flat"
                prepend-icon="mdi-ticket-confirmation-outline"
                @click="router.visit(bets({ query: { game_id: game.id } }))"
                >View bets</v-btn
            >
        </template>
        <div class="lucky-four-game">
            <v-card
                tag="section"
                class="game-panel matchup-panel"
                rounded="xl"
                aria-label="Game overview"
            >
                <div class="matchup-topline">
                    <span class="league-badge"
                        ><v-icon icon="mdi-basketball" size="17" />{{
                            game.league || 'Basketball'
                        }}</span
                    >
                    <div class="game-statuses">
                        <v-chip size="small" color="#526581" variant="tonal">{{
                            game.status || 'Scheduled'
                        }}</v-chip>
                        <span class="betting-badge" :class="{ 'is-open': bettingOpen }"
                            ><span class="status-dot" />Betting
                            {{ bettingOpen ? 'open' : 'closed' }}</span
                        >
                    </div>
                </div>
                <div class="matchup-teams">
                    <div class="matchup-team">
                        <div class="team-logo">
                            <EntityLogo
                                :src="game.team_a_logo"
                                :name="game.team_a_name"
                                :size="64"
                            />
                        </div>
                        <h2>{{ game.team_a_name }}</h2>
                    </div>
                    <span class="matchup-versus">VS</span>
                    <div class="matchup-team">
                        <div class="team-logo">
                            <EntityLogo
                                :src="game.team_b_logo"
                                :name="game.team_b_name"
                                :size="64"
                            />
                        </div>
                        <h2>{{ game.team_b_name }}</h2>
                    </div>
                </div>
                <div class="matchup-schedule">
                    <v-icon icon="mdi-calendar-clock-outline" size="17" /><span>{{
                        displayDate(game.schedule)
                    }}</span
                    ><span class="schedule-timezone">Philippine time</span>
                </div>
            </v-card>

            <section class="prize-grid" aria-label="Prizes and bet amount">
                <article class="jackpot-card">
                    <div class="prize-heading">
                        <span class="prize-icon"
                            ><v-icon icon="mdi-trophy-outline" size="21"
                        /></span>
                        <h2>Jackpot prize</h2>
                        <span class="jackpot-tag" title="Added to the jackpot from bets"
                            >+{{ money(prizes?.amount_added ?? 0) }} added</span
                        >
                    </div>
                    <div class="prize-value">
                        {{ prizes ? money(prizes.jackpot_prize) : '—' }}<span>tokens</span>
                    </div>
                    <p
                        title="Shared equally among exact four-digit winners, rounded down to cents."
                    >
                        Exact 4-digit match. Shared equally.
                    </p>
                </article>
                <article class="prize-card">
                    <div class="prize-heading">
                        <span class="prize-icon"
                            ><v-icon icon="mdi-numeric-3-box-outline" size="21"
                        /></span>
                        <h2>Three-digit prize</h2>
                    </div>
                    <div class="prize-value">
                        {{ prizes ? money(prizes.three_digits_prize) : '—' }}<span>tokens</span>
                    </div>
                    <p>First or last 3 digits; excludes jackpot winners.</p>
                </article>
                <article class="prize-card">
                    <div class="prize-heading">
                        <span class="prize-icon"
                            ><v-icon icon="mdi-ticket-confirmation-outline" size="21"
                        /></span>
                        <h2>Bet amount</h2>
                    </div>
                    <div class="prize-value">
                        {{ prizes ? money(prizes.bet_amount) : '—' }}<span>tokens</span>
                    </div>
                    <p>
                        <strong>{{ contribution }}</strong> pool contribution
                    </p>
                </article>
            </section>

            <v-card
                tag="section"
                class="game-panel pool-panel"
                rounded="xl"
                aria-label="Betting activity"
            >
                <dl class="pool-metrics">
                    <div>
                        <dt>Bets placed</dt>
                        <dd>{{ totals.bets.toLocaleString() }}</dd>
                    </div>
                    <div>
                        <dt>Total wagered</dt>
                        <dd>{{ money(totals.amount) }}</dd>
                    </div>
                    <div>
                        <dt>Prizes released</dt>
                        <dd>{{ money(totals.released) }}</dd>
                    </div>
                </dl>
                <div v-if="can('Lucky Four Enable Disable')" class="pool-refresh">
                    <v-btn
                        class="game-action"
                        variant="tonal"
                        color="primary"
                        prepend-icon="mdi-refresh"
                        :disabled="
                            !migrationReady ||
                            game.betting !== 'Disabled' ||
                            !totals.pending ||
                            form.processing
                        "
                        :loading="refreshForm.processing"
                        @click="refreshPool"
                        >Refresh jackpot<span v-if="totals.pending" class="pending-count">{{
                            totals.pending.toLocaleString()
                        }}</span></v-btn
                    >
                    <p>{{ refreshHint }}</p>
                </div>
            </v-card>

            <v-card
                tag="section"
                class="game-panel results-panel"
                rounded="xl"
                aria-labelledby="quarter-results-title"
            >
                <header class="panel-heading">
                    <div>
                        <h2 id="quarter-results-title">Quarter results</h2>
                        <p>Each quarter adds one digit to the winning combination.</p>
                    </div>
                    <span
                        class="results-progress"
                        :class="{ 'is-complete': recordedQuarters === 4 }"
                        ><v-icon
                            :icon="
                                recordedQuarters === 4
                                    ? 'mdi-check-circle-outline'
                                    : 'mdi-clock-outline'
                            "
                            size="16"
                        />{{ recordedQuarters }} / 4 recorded</span
                    >
                </header>
                <div class="quarter-grid">
                    <div
                        v-for="quarter in quarters"
                        :key="quarter.quarter"
                        class="quarter-card"
                        :class="{ 'is-recorded': quarter.recorded }"
                    >
                        <span class="quarter-label">Quarter {{ quarter.quarter }}</span>
                        <strong class="quarter-digit">{{
                            quarter.recorded ? quarter.digit : '—'
                        }}</strong>
                        <span
                            v-if="quarter.result"
                            class="quarter-score"
                            :aria-label="`${game.team_a_name}: ${quarter.result.team_a_score}, ${game.team_b_name}: ${quarter.result.team_b_score}`"
                            >{{ quarter.result.team_a_score }} <span>:</span>
                            {{ quarter.result.team_b_score }}</span
                        >
                        <span v-else class="quarter-state">Awaiting result</span>
                    </div>
                </div>
                <div class="winning-combination" :class="{ 'is-complete': combination !== null }">
                    <div>
                        <h3>Winning combination</h3>
                        <p>
                            {{
                                combination === null
                                    ? 'Awaiting all four quarters'
                                    : 'All four quarter results are recorded'
                            }}
                        </p>
                    </div>
                    <span
                        class="combination-code"
                        :aria-label="
                            combination === null
                                ? 'Winning combination pending'
                                : `Winning combination ${combination}`
                        "
                        >{{ combination ?? '— — — —' }}</span
                    >
                </div>
                <p class="results-explanation">
                    <v-icon icon="mdi-information-outline" size="15" />Each digit is the last digit
                    of the higher score entered for that quarter in Ending.
                </p>
            </v-card>
            <v-card class="game-panel workflow-panel" rounded="xl">
                <v-tabs v-model="tab" color="primary"
                    ><v-tab value="winners" prepend-icon="mdi-trophy-outline"
                        >Winners<span class="tab-count">{{
                            winners.total.toLocaleString()
                        }}</span></v-tab
                    ><v-tab value="activity" prepend-icon="mdi-history"
                        >Activity<span class="tab-count">{{
                            (audits.length + legacyLogs.length).toLocaleString()
                        }}</span></v-tab
                    ></v-tabs
                >
                <div v-if="tab === 'winners'">
                    <v-alert
                        v-if="!finished && winners.data.length"
                        type="info"
                        variant="tonal"
                        class="ma-4"
                        >Winner processing is available after the Ending game finishes with four
                        results and betting closed.</v-alert
                    >
                    <div v-if="!winners.data.length" class="workflow-empty" role="status">
                        <span class="empty-icon"
                            ><v-icon
                                :icon="finished ? 'mdi-ticket-outline' : 'mdi-trophy-outline'"
                                size="28"
                        /></span>
                        <h3>{{ finished ? 'No winning tickets' : 'Waiting for game results' }}</h3>
                        <p>
                            {{
                                finished
                                    ? 'No tickets matched the winning combination for this game.'
                                    : 'Winners can be reviewed once the game is finished, betting is closed, and all four results are recorded.'
                            }}
                        </p>
                        <div v-if="!finished" class="winner-checklist">
                            <span :class="{ 'is-done': recordedQuarters === 4 }"
                                ><v-icon
                                    :icon="
                                        recordedQuarters === 4
                                            ? 'mdi-check-circle'
                                            : 'mdi-circle-outline'
                                    "
                                    size="15"
                                />Four quarter results</span
                            >
                            <span :class="{ 'is-done': game.game_status_id === 5 }"
                                ><v-icon
                                    :icon="
                                        game.game_status_id === 5
                                            ? 'mdi-check-circle'
                                            : 'mdi-circle-outline'
                                    "
                                    size="15"
                                />Game finished</span
                            >
                            <span :class="{ 'is-done': !bettingOpen }"
                                ><v-icon
                                    :icon="!bettingOpen ? 'mdi-check-circle' : 'mdi-circle-outline'"
                                    size="15"
                                />Betting closed</span
                            >
                        </div>
                    </div>
                    <v-table v-else class="ending-table winner-table"
                        ><thead>
                            <tr>
                                <th>Ending bet</th>
                                <th>Player</th>
                                <th>Combination</th>
                                <th>Prize tier</th>
                                <th>Prize</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="bet in winners.data" :key="bet.id">
                                <td data-label="Ending bet">#{{ bet.ending_bet_id }}</td>
                                <td data-label="Player">
                                    {{ bet.player_name || 'Unresolved player' }}
                                </td>
                                <td data-label="Combination">
                                    <span class="ticket-combination">{{ bet.combination }}</span>
                                </td>
                                <td data-label="Prize tier">
                                    {{ bet.tier || 'Historical winner — review required' }}
                                </td>
                                <td data-label="Prize">
                                    {{ money(bet.prize_amount ?? bet.calculated_prize ?? null) }}
                                </td>
                                <td data-label="Status">
                                    <v-chip
                                        size="small"
                                        :color="
                                            bet.winner_status === 'Approved'
                                                ? 'success'
                                                : bet.winner_status === 'Verified'
                                                  ? '#b77910'
                                                  : '#526581'
                                        "
                                        variant="tonal"
                                        >{{ bet.winner_status || 'For verification' }}</v-chip
                                    >
                                    <div
                                        v-if="bet.prize_released === 'Yes'"
                                        class="text-success text-caption"
                                    >
                                        Prize released
                                    </div>
                                </td>
                                <td data-label="Actions" class="winner-actions">
                                    <div
                                        v-if="
                                            bet.prize_released !== 'Yes' &&
                                            bet.winner_status !== 'Approved'
                                        "
                                        class="d-flex ga-1 py-2"
                                    >
                                        <v-btn
                                            v-if="
                                                can('Lucky Four Winner Verification') &&
                                                bet.winner_status !== 'Verified'
                                            "
                                            size="small"
                                            variant="text"
                                            :disabled="
                                                !migrationReady ||
                                                !finished ||
                                                !bet.tier ||
                                                !bet.player_id ||
                                                !!totals.pending ||
                                                form.processing
                                            "
                                            @click="confirm(bet, 'verify')"
                                            >Verify</v-btn
                                        >
                                        <v-btn
                                            v-if="
                                                can('Lucky Four Winner Verification') &&
                                                bet.winner_status === 'Verified'
                                            "
                                            size="small"
                                            variant="text"
                                            :disabled="!migrationReady || form.processing"
                                            @click="confirm(bet, 'unverify')"
                                            >Unverify</v-btn
                                        >
                                        <v-btn
                                            v-if="
                                                can('Lucky Four Winner Approval') &&
                                                bet.winner_status === 'Verified'
                                            "
                                            size="small"
                                            color="primary"
                                            :disabled="
                                                !migrationReady ||
                                                !finished ||
                                                !bet.tier ||
                                                !bet.player_id ||
                                                !!totals.pending ||
                                                form.processing
                                            "
                                            @click="confirm(bet, 'approve')"
                                            >Approve &amp; pay</v-btn
                                        >
                                    </div>
                                </td>
                            </tr>
                        </tbody></v-table
                    ><EndingPager v-if="winners.total > 0" :page="winners" />
                </div>
                <div v-else class="activity-panel">
                    <h2 class="activity-heading">Recent activity</h2>
                    <div v-if="!audits.length" class="activity-empty">
                        <v-icon icon="mdi-history" size="24" />
                        <div>
                            <h3>No activity yet</h3>
                            <p>Jackpot refreshes and winner reviews will appear here.</p>
                        </div>
                    </div>
                    <v-table v-else class="ending-table"
                        ><thead>
                            <tr>
                                <th>When</th>
                                <th>Action</th>
                                <th>Administrator</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in audits" :key="log.id">
                                <td>{{ displayDate(log.created_at) }}</td>
                                <td>{{ log.action.replaceAll('_', ' ') }}</td>
                                <td>{{ log.actor || '—' }}</td>
                            </tr>
                        </tbody></v-table
                    >
                    <h2 class="activity-heading imported-heading">Imported activity</h2>
                    <p v-if="!legacyLogs.length" class="activity-note">
                        No historical activity was imported for this game.
                    </p>
                    <v-table v-else class="ending-table"
                        ><thead>
                            <tr>
                                <th>When</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in legacyLogs" :key="log.id">
                                <td>{{ displayDate(log.created_at) }}</td>
                                <td>{{ log.details || log.action }}</td>
                            </tr>
                        </tbody></v-table
                    >
                </div>
            </v-card>
        </div>
        <v-dialog
            :model-value="!!confirmation"
            max-width="480"
            aria-labelledby="lucky-four-confirm-title"
            :persistent="form.processing"
            @update:model-value="
                (value) => {
                    if (!value) confirmation = null;
                }
            "
            ><v-card v-if="confirmation">
                <v-card-title id="lucky-four-confirm-title">{{
                    confirmation.action === 'approve'
                        ? 'Approve & pay winner'
                        : confirmation.action === 'verify'
                          ? 'Verify winner'
                          : 'Unverify winner'
                }}</v-card-title>
                <v-card-text
                    ><p>
                        Ending bet #{{ confirmation.bet.ending_bet_id }} ·
                        {{ confirmation.bet.combination }} · {{ confirmation.bet.player_name }}
                    </p>
                    <p v-if="confirmation.action === 'approve'" class="mt-3">
                        Credit {{ money(confirmation.bet.prize_amount) }} to this player's wallet. A
                        released prize cannot be unverified.
                    </p>
                    <v-alert v-if="form.hasErrors" type="error" variant="tonal" class="mt-3">{{
                        Object.values(form.errors).join(' ')
                    }}</v-alert></v-card-text
                >
                <v-card-actions
                    ><v-spacer /><v-btn :disabled="form.processing" @click="confirmation = null"
                        >Cancel</v-btn
                    ><v-btn color="primary" :loading="form.processing" @click="submit"
                        >Confirm</v-btn
                    ></v-card-actions
                >
            </v-card></v-dialog
        >
    </LuckyFourShell>
</template>

<style scoped>
.lucky-four-game {
    display: grid;
    gap: 20px;
    color: #172b4d;
}
.game-action {
    text-transform: none;
    letter-spacing: 0;
    font-weight: 500;
    font-size: 12px !important;
    border-radius: 8px !important;
}
.game-action.v-btn--variant-outlined {
    border-color: #d8e0eb;
    color: #445672;
    background: #fff;
}
.game-panel {
    border: 1px solid #e4e9f1;
    border-radius: 16px !important;
    min-width: 0;
    color: inherit;
    box-shadow: 0 2px 4px #172b4d03 !important;
}
.matchup-panel {
    padding: 20px 24px;
}
.matchup-topline,
.game-statuses,
.league-badge,
.betting-badge,
.matchup-schedule {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
}
.matchup-topline {
    justify-content: space-between;
    gap: 12px;
}
.league-badge {
    gap: 8px;
    color: #38628a;
    font-size: 13px;
    font-weight: 500;
}
.league-badge .v-icon {
    color: #00a3e4;
}
.game-statuses {
    gap: 12px;
    font-size: 12px;
}
.betting-badge {
    gap: 6px;
    color: #64748b;
}
.betting-badge.is-open {
    color: #15815b;
}
.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}
.matchup-teams {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 42px minmax(0, 1fr);
    align-items: center;
    gap: 28px;
    max-width: 720px;
    margin: 20px auto 16px;
}
.matchup-team {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    min-width: 0;
}
.matchup-team h2 {
    font-size: 23px;
    line-height: 1.35;
    overflow-wrap: anywhere;
    letter-spacing: -0.5px;
}
.team-logo {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 76px;
    height: 76px;
    border: 1px solid #e8eef5;
    border-radius: 16px;
    background: #f8fafc;
}
.team-logo :deep(.ending-logo-fallback) {
    font-size: 25px;
    font-weight: 600;
    color: #5483ac;
    background: transparent;
}
.matchup-versus {
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    background: #f1f4f9;
    border-radius: 50%;
    font-size: 10px;
    font-weight: 600;
    color: #8a99af;
}
.matchup-schedule {
    justify-content: center;
    gap: 8px;
    font-size: 12px;
    color: #526581;
}
.schedule-timezone {
    padding-left: 10px;
    margin-left: 2px;
    border-left: 1px solid #dbe2ec;
    color: #8895a7;
    font-size: 11px;
}
.prize-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr 1fr;
    gap: 12px;
}
.jackpot-card,
.prize-card {
    padding: 14px 16px;
    border-radius: 12px;
    min-width: 0;
}
.jackpot-card {
    color: #fff;
    background: linear-gradient(115deg, #183a73, #24559b);
    border: 1px solid #264f8b;
}
.prize-card {
    background: #fff;
    border: 1px solid #e4e9f1;
}
.prize-heading {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
.prize-heading h2 {
    margin: 0;
    line-height: 1.3;
    font-size: 11px;
    font-weight: 500;
    color: #61718a;
}
.prize-icon {
    display: grid;
    place-items: center;
    width: 24px;
    height: 24px;
    flex-shrink: 0;
    border-radius: 7px;
    background: #eff6fd;
    color: #3286c0;
}
.jackpot-card .prize-icon {
    background: #ffffff14;
    color: #ffd47a;
}
.jackpot-card .prize-heading h2 {
    color: #d9e9ff;
}
.jackpot-tag {
    padding: 3px 8px;
    margin-left: auto;
    border: 1px solid #ffffff30;
    border-radius: 20px;
    font-size: 10px;
    color: #d9e9ff;
    white-space: nowrap;
}
.prize-value {
    font-size: 26px;
    font-weight: 600;
    line-height: 1.15;
    letter-spacing: -0.8px;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}
.jackpot-card .prize-value {
    font-size: 28px;
}
.prize-value > span {
    font-size: 11px;
    font-weight: 400;
    letter-spacing: 0;
    margin-left: 8px;
    color: #8290a4;
    white-space: nowrap;
}
.jackpot-card .prize-value > span {
    color: #bacfec;
}
.prize-card p,
.jackpot-card p {
    font-size: 11px;
    line-height: 1.5;
    color: #64748b;
    margin: 5px 0 0;
}
.jackpot-card p {
    color: #c2d5ef;
}
.pool-panel {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 20px 24px;
}
.pool-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    flex-grow: 1;
    gap: 24px;
    margin: 0;
}
.pool-metrics > div + div {
    border-left: 1px solid #e8edf4;
    padding-left: 24px;
}
.pool-metrics dt {
    color: #74839a;
    font-size: 11px;
    margin-bottom: 4px;
}
.pool-metrics dd {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}
.pool-refresh {
    flex-basis: 320px;
    flex-shrink: 0;
    text-align: right;
}
.pool-refresh p {
    margin-top: 7px;
    font-size: 11px;
    line-height: 1.6;
    color: #64748b;
}
.pending-count,
.tab-count {
    display: inline-block;
    margin-left: 8px;
    padding: 1px 7px;
    border-radius: 12px;
    font-size: 10px;
    background: #eaf1f9;
    color: #526581;
}
.results-panel {
    padding: 24px;
}
.panel-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}
.panel-heading h2 {
    font-size: 17px;
    letter-spacing: -0.3px;
}
.panel-heading p {
    margin-top: 4px;
    font-size: 12px;
    color: #64748b;
}
.results-progress {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    background: #f1f5f9;
    color: #6f7e94;
    border-radius: 8px;
    font-size: 11px;
    white-space: nowrap;
}
.results-progress.is-complete {
    color: #15815b;
    background: #edf8f2;
}
.quarter-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}
.quarter-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 10px;
    gap: 6px;
    border: 1px solid #e8edf4;
    border-radius: 12px;
    background: #fafbfd;
}
.quarter-card.is-recorded {
    border-color: #c9dff1;
    background: #f5faff;
}
.quarter-label {
    font-size: 11px;
    font-weight: 500;
    color: #64748b;
}
.quarter-digit {
    font-size: 34px;
    font-weight: 600;
    line-height: 1.3;
    color: #bcc7d5;
    font-variant-numeric: tabular-nums;
}
.is-recorded .quarter-digit {
    color: #25588d;
}
.quarter-state,
.quarter-score {
    font-size: 10px;
    color: #64748b;
    text-align: center;
}
.quarter-score {
    color: #58718c;
    font-variant-numeric: tabular-nums;
}
.quarter-score > span {
    margin-inline: 5px;
    color: #a6b3c3;
}
.winning-combination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 18px;
    padding: 16px 20px;
    border-radius: 12px;
    background: #f4f7fb;
}
.winning-combination.is-complete {
    background: #edf6ff;
}
.winning-combination h3 {
    font-size: 12px;
    font-weight: 500;
}
.winning-combination p {
    margin-top: 4px;
    font-size: 11px;
    color: #64748b;
}
.combination-code {
    color: #8b9cb2;
    font-size: 27px;
    line-height: 1.2;
    font-weight: 600;
    letter-spacing: 8px;
    font-variant-numeric: tabular-nums;
}
.is-complete .combination-code {
    color: #235b95;
}
.results-explanation {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    margin-top: 14px;
    font-size: 11px;
    line-height: 1.6;
    color: #64748b;
}
.results-explanation .v-icon {
    margin-top: 1px;
    flex-shrink: 0;
}
.workflow-panel :deep(.v-tabs) {
    padding-inline: 8px;
    border-bottom: 1px solid #e8edf4;
}
.workflow-panel :deep(.v-tab) {
    text-transform: none;
    letter-spacing: 0;
    font-size: 12px;
    font-weight: 500;
}
.workflow-empty {
    padding: 32px 24px;
    text-align: center;
}
.empty-icon {
    display: grid;
    place-items: center;
    width: 56px;
    height: 56px;
    margin: 0 auto 14px;
    border-radius: 50%;
    color: #6e95bd;
    background: #eef5fc;
}
.workflow-empty h3 {
    font-size: 15px;
    margin-bottom: 6px;
}
.workflow-empty > p {
    max-width: 490px;
    margin-inline: auto;
    font-size: 12px;
    line-height: 1.8;
    color: #64748b;
}
.winner-checklist {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px 20px;
    margin-top: 18px;
    color: #64748b;
    font-size: 11px;
}
.winner-checklist > span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.winner-checklist .is-done {
    color: #15815b;
}
.winner-table :deep(table) {
    min-width: 800px;
}
.winner-table :deep(thead) {
    background: #f9fbfd;
    color: #74839a;
}
.ticket-combination {
    padding: 5px 8px;
    border-radius: 6px;
    background: #eff4fa;
    color: #355b84;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 2px;
    font-variant-numeric: tabular-nums;
}
.activity-panel {
    padding: 24px;
}
.activity-heading {
    font-size: 13px;
    margin-bottom: 14px;
}
.imported-heading {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #e8edf4;
}
.activity-empty {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px;
    border-radius: 10px;
    background: #f8fafc;
    color: #8a9ab0;
}
.activity-empty h3 {
    font-size: 12px;
    color: #526581;
}
.activity-empty p,
.activity-note {
    margin-top: 4px;
    font-size: 12px;
    color: #64748b;
}
@media (max-width: 1100px) {
    .pool-panel {
        flex-wrap: wrap;
        gap: 18px;
    }
    .pool-refresh {
        flex-basis: 100%;
        display: flex;
        align-items: center;
        gap: 14px;
        text-align: left;
        padding-top: 16px;
        border-top: 1px solid #e8edf4;
    }
    .pool-refresh p {
        margin-top: 0;
    }
    .prize-grid {
        grid-template-columns: 1.2fr 1fr 1fr;
        gap: 12px;
    }
}
@media (max-width: 600px) {
    .game-action {
        padding-inline: 10px;
    }
    .lucky-four-game {
        gap: 16px;
    }
    .matchup-panel,
    .results-panel,
    .pool-panel,
    .activity-panel {
        padding: 18px 16px;
    }
    .matchup-teams {
        gap: 12px;
        grid-template-columns: minmax(0, 1fr) 30px minmax(0, 1fr);
        margin-block: 24px 18px;
    }
    .matchup-team {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
    .matchup-team h2 {
        font-size: 18px;
    }
    .matchup-versus {
        width: 30px;
        height: 30px;
    }
    .schedule-timezone {
        display: none;
    }
    .jackpot-card,
    .prize-card {
        padding: 14px;
    }
    .prize-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .jackpot-card {
        grid-column: 1 / -1;
    }
    .prize-heading {
        gap: 7px;
        margin-bottom: 8px;
    }
    .prize-heading h2 {
        font-size: 11px;
    }
    .prize-icon {
        width: 24px;
        height: 24px;
        border-radius: 7px;
    }
    .prize-value > span {
        display: block;
        margin: 4px 0 0;
    }
    .jackpot-card .prize-value > span {
        display: inline;
        margin-left: 8px;
    }
    .pool-metrics {
        gap: 12px;
    }
    .pool-metrics > div + div {
        padding-left: 12px;
    }
    .pool-metrics dd {
        font-size: 16px;
    }
    .pool-metrics dt {
        font-size: 10px;
    }
    .pool-refresh {
        flex-wrap: wrap;
        gap: 8px;
    }
    .quarter-grid {
        gap: 8px;
    }
    .quarter-card {
        padding: 12px 4px;
    }
    .quarter-label,
    .quarter-state {
        font-size: 10px;
    }
    .quarter-digit {
        font-size: 28px;
    }
    .winning-combination {
        padding: 14px;
    }
    .combination-code {
        font-size: 24px;
    }
    .winner-table :deep(table) {
        min-width: 0;
        width: 100%;
    }
    .winner-table :deep(thead) {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip-path: inset(50%);
    }
    .winner-table :deep(tbody tr) {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        padding: 20px 16px;
        border-bottom: 1px solid #e8edf4;
    }
    .winner-table :deep(td) {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
        height: auto !important;
        border: 0 !important;
        padding: 0 !important;
        overflow-wrap: anywhere;
    }
    .winner-table :deep(td::before) {
        content: attr(data-label);
        font-size: 10px;
        font-weight: 400;
        color: #64748b;
    }
    .winner-table :deep(.winner-actions) {
        grid-column: 1 / -1;
    }
    .winner-table :deep(.winner-actions > div) {
        flex-wrap: wrap;
        padding-block: 0 !important;
        gap: 8px !important;
    }
}
</style>
