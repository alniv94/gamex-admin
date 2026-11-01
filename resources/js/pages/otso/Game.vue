<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    bets,
    pdf,
    betting,
    cancel,
    importBets,
    storeResult,
    updateResult,
    approveResult,
    generate,
    approveWinners,
} from '@/actions/App/Http/Controllers/OtsoPanaloController';
import { usePermissions } from '@/composables/usePermissions';
import OtsoShell from '@/components/otso/OtsoShell.vue';
import GameForm from '@/components/otso/GameForm.vue';
import EntityLogo from '@/components/ending/EntityLogo.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import { money } from '@/types/ending';
import type { PageResult } from '@/types/ending';
import { quarters, prizeFields, displayOtsoDate as displayDate } from '@/types/otso';
import type { OtsoGame, OtsoConfig, AvailableGame, OtsoResult, OtsoWinner } from '@/types/otso';
interface Activity {
    id: number;
    action: string;
    actor: string | null;
    created_at: string | null;
    details?: string | null;
}
const props = defineProps<{
    game: OtsoGame;
    result: OtsoResult | null;
    resultStatus: string | null;
    canViewResult: boolean;
    availableGames: AvailableGame[];
    activeConfig: OtsoConfig | null;
    migrationReady: boolean;
    summary: {
        bets: number;
        total: string | number;
        refunded: string | number;
        gross: string | number;
        winnings: string | number;
    };
    winners: PageResult<OtsoWinner> | null;
    scores: { quarter_id: number; team_a_score: number; team_b_score: number }[];
    audits: Activity[];
    legacyLogs: Activity[];
}>();
const { can } = usePermissions();
const page = usePage();
const edit = ref(false);
const resultDialog = ref(false);
const tab = ref(props.winners ? 'winners' : 'activity');
const resultForm = useForm({
    first_quarter_result: '',
    second_quarter_result: '',
    third_quarter_result: '',
    fourth_quarter_result: '',
    version: props.result?.version || 1,
});
watch(
    () => props.result,
    (result) => {
        for (const quarter of quarters) resultForm[quarter.result] = result?.[quarter.result] || '';
        resultForm.version = result?.version || 1;
    },
    { immediate: true },
);
type QuarterResultField = (typeof quarters)[number]['result'];
const resultCombination = computed(() => {
    const values = props.result || resultForm;
    if (!quarters.some((quarter) => values[quarter.result])) return 'No quarter input yet';
    return quarters
        .map((quarter) =>
            /^\d-\d$/.test(values[quarter.result] || '') ? values[quarter.result] : '--',
        )
        .join(' | ');
});
const completedQuarters = computed(
    () =>
        quarters.filter((quarter) =>
            /^\d-\d$/.test((props.result || resultForm)[quarter.result] || ''),
        ).length,
);
const completionPercent = computed(() => (completedQuarters.value / quarters.length) * 100);
const hasPartialQuarter = computed(() =>
    quarters.some(
        (quarter) => resultForm[quarter.result] && !/^\d-\d$/.test(resultForm[quarter.result]),
    ),
);
function quarterPreview(field: QuarterResultField) {
    return /^\d-\d$/.test(resultForm[field]) ? resultForm[field] : '--';
}
function resultDigit(field: QuarterResultField, team: 0 | 1) {
    return resultForm[field].split('-')[team] || '';
}
function updateResultDigit(field: QuarterResultField, team: 0 | 1, value: string | null) {
    const digits = [resultDigit(field, 0), resultDigit(field, 1)];
    digits[team] = String(value ?? '')
        .replace(/\D/g, '')
        .slice(0, 1);
    resultForm[field] = digits.some(Boolean) ? digits.join('-') : '';
    resultForm.clearErrors(field);
}
function clearResultInputs() {
    for (const quarter of quarters) resultForm[quarter.result] = '';
    resultForm.clearErrors();
}
function syncResultDigit(event: Event, field: QuarterResultField, team: 0 | 1) {
    if (event.target instanceof HTMLInputElement) event.target.value = resultDigit(field, team);
}
const grossGamingRevenue = computed(
    () => Number(props.summary.gross) - Number(props.summary.winnings),
);
const cancelled = computed(() => Number(props.game.game_status_id) === 4);
const finished = computed(() => Number(props.game.game_status_id) === 3);
const canEdit = computed(
    () =>
        can('Update Otso Panalo') &&
        Number(props.game.game_status_id) === 1 &&
        props.game.betting === 'Disabled',
);
function saveResult() {
    if (resultForm.processing || !props.migrationReady || cancelled.value) return;
    resultForm.clearErrors();
    for (const quarter of quarters) {
        if (resultForm[quarter.result] && !/^\d-\d$/.test(resultForm[quarter.result])) {
            resultForm.setError(quarter.result, 'Complete both digits for this quarter.');
        }
    }
    if (!quarters.some((quarter) => resultForm[quarter.result])) {
        resultForm.setError('first_quarter_result', 'At least one quarter result is required.');
    }
    if (resultForm.hasErrors) return;
    const options = { preserveScroll: true, onSuccess: () => (resultDialog.value = false) };
    if (props.result) resultForm.put(updateResult.url(props.game.id), options);
    else resultForm.post(storeResult.url(props.game.id), options);
}
function useScores() {
    resultForm.clearErrors();
    for (const quarter of quarters) {
        const score = props.scores.find((item) => item.quarter_id === quarter.number);
        if (score)
            resultForm[quarter.result] =
                String(score.team_a_score % 10) + '-' + String(score.team_b_score % 10);
    }
}
type Action = 'betting' | 'cancel' | 'import' | 'approveResult' | 'generate' | 'approveWinners';
const pending = ref<Action | null>(null);
const actionForm = useForm({
    enabled: false,
    reason: 'cancelled',
    version: props.result?.version || 1,
    winner_id: null as number | null,
});
const selectedWinner = computed(() =>
    props.winners?.data.find((winner) => winner.id === actionForm.winner_id),
);
const confirmationButtonColors: Record<string, string> = {
    primary: '#007eaf',
    success: '#237a3b',
    warning: '#8a5200',
    error: '#c62828',
};
const confirmation = computed(() => {
    const disablingBetting = props.game.betting === 'Enabled';
    switch (pending.value) {
        case 'betting':
            return {
                title: disablingBetting ? 'Disable Betting' : 'Enable Betting',
                description: disablingBetting
                    ? 'Close betting for this game and stop accepting new wagers.'
                    : 'Open betting for this game so players can place their wagers.',
                label: disablingBetting ? 'Disable Betting' : 'Enable Betting',
                color: disablingBetting ? 'warning' : 'success',
                icon: disablingBetting ? 'mdi-lock-outline' : 'mdi-lock-open-outline',
                notice: disablingBetting
                    ? 'Existing bets will remain in this game.'
                    : 'Bets can be placed until the betting deadline.',
                noticeIcon: 'mdi-information-outline',
            };
        case 'cancel':
            return {
                title: 'Cancel Game',
                description:
                    'Cancel this game and refund all outstanding wagers to player wallets.',
                label: 'Cancel Game & Refund',
                color: 'error',
                icon: 'mdi-cancel',
                notice: 'This action cannot be undone. Betting will be disabled for this game.',
                noticeIcon: 'mdi-alert-circle-outline',
            };
        case 'import':
            return {
                title: 'Import Legacy Bets',
                description: 'Import additional legacy bets into this game.',
                label: 'Import Bets',
                color: 'primary',
                icon: 'mdi-database-import-outline',
                notice: 'Existing records will be checked for conflicts before importing.',
                noticeIcon: 'mdi-information-outline',
            };
        case 'approveResult':
            return {
                title: 'Approve Result',
                description:
                    'Review the saved quarter results before approving them for this game.',
                label: 'Approve Result',
                color: 'success',
                icon: 'mdi-check-decagram-outline',
                notice: 'Approved results cannot be edited. Verify every quarter before continuing.',
                noticeIcon: 'mdi-lock-outline',
            };
        case 'generate':
            return {
                title: 'Generate Winners',
                description: 'Find winning tickets using the approved quarter results below.',
                label: 'Generate Winners',
                color: 'primary',
                icon: 'mdi-trophy-outline',
                notice: 'Winners will need approval before eligible secondary prizes are credited.',
                noticeIcon: 'mdi-information-outline',
            };
        case 'approveWinners':
            return {
                title: actionForm.winner_id ? 'Approve Winner' : 'Approve All Winners',
                description: actionForm.winner_id
                    ? 'Approve the selected winning ticket for this game.'
                    : 'Approve all pending winners across every page for this game.',
                label: actionForm.winner_id ? 'Approve Winner' : 'Approve All Winners',
                color: 'success',
                icon: 'mdi-shield-check-outline',
                notice: actionForm.winner_id
                    ? 'Eligible secondary prizes will be credited to the player wallet.'
                    : 'Eligible secondary prizes will be credited to player wallets.',
                noticeIcon: 'mdi-wallet-outline',
            };
        default:
            return null;
    }
});
function ask(action: Action, winnerId: number | null = null) {
    actionForm.clearErrors();
    actionForm.winner_id = winnerId;
    actionForm.version = props.result?.version || 1;
    actionForm.enabled = props.game.betting !== 'Enabled';
    pending.value = action;
}
function requestBettingChange() {
    if (!props.migrationReady || cancelled.value || finished.value || actionForm.processing) return;
    ask('betting');
}
function confirm() {
    const routes = { betting, cancel, import: importBets, approveResult, generate, approveWinners };
    if (!pending.value) return;
    const route = routes[pending.value](props.game.id);
    actionForm.submit(route.method, route.url, {
        preserveScroll: true,
        onSuccess: () => (pending.value = null),
    });
}
</script>
<template>
    <OtsoShell title="Game Details" :migration-ready="migrationReady">
        <v-card class="pa-3 pa-sm-4 pt-sm-5">
            <div class="position-relative text-center">
                <h2 class="text-subtitle-1 font-weight-regular text-grey-darken-1 ma-0">
                    {{ game.league || 'Otso Panalo' }}
                </h2>
                <div
                    class="otso-game-actions d-flex flex-wrap justify-center justify-sm-end ga-2 mt-3 mt-sm-0"
                >
                    <v-btn
                        v-if="canEdit"
                        color="primary"
                        variant="outlined"
                        size="small"
                        :disabled="!migrationReady"
                        prepend-icon="mdi-pencil"
                        @click="edit = true"
                        >Edit Game</v-btn
                    >
                    <v-btn
                        v-if="can('Otso Panalo Game Settings') && !cancelled && !finished"
                        color="error"
                        variant="outlined"
                        size="small"
                        :disabled="!migrationReady"
                        prepend-icon="mdi-cancel"
                        @click="ask('cancel')"
                        >Cancel Game</v-btn
                    >
                </div>
                <div class="d-flex align-center justify-center ga-3 ga-sm-8 my-5 my-sm-6">
                    <div class="otso-matchup-team text-center">
                        <v-avatar size="80" rounded="0" color="blue-lighten-5" class="mb-2 pa-2"
                            ><EntityLogo
                                :src="game.team_a_logo"
                                :name="game.team_a_name"
                                :size="64"
                        /></v-avatar>
                        <div>{{ game.team_a_name }} {{ game.team_a_alias }}</div>
                    </div>
                    <span class="font-italic">vs</span>
                    <div class="otso-matchup-team text-center">
                        <v-avatar
                            size="80"
                            rounded="0"
                            color="deep-orange-lighten-5"
                            class="mb-2 pa-2"
                            ><EntityLogo
                                :src="game.team_b_logo"
                                :name="game.team_b_name"
                                :size="64"
                        /></v-avatar>
                        <div>{{ game.team_b_name }} {{ game.team_b_alias }}</div>
                    </div>
                </div>
            </div>
            <v-row gap="20">
                <v-col cols="12" sm="6" lg="4" tag="section">
                    <h3
                        class="otso-section-label text-primary text-uppercase font-weight-regular mb-2"
                    >
                        Game Details
                    </h3>
                    <v-table class="otso-details-table" density="compact" aria-label="Game details">
                        <tbody>
                            <tr>
                                <th scope="row">Game ID</th>
                                <td>#{{ game.id }}</td>
                            </tr>
                            <tr>
                                <th scope="row">Schedule</th>
                                <td>{{ displayDate(game.schedule) }}</td>
                            </tr>
                            <tr>
                                <th scope="row">Betting Deadline</th>
                                <td>{{ displayDate(game.betting_deadline) }}</td>
                            </tr>
                            <tr>
                                <th scope="row">Status</th>
                                <td>
                                    <v-chip
                                        size="x-small"
                                        variant="tonal"
                                        :color="cancelled ? 'error' : finished ? 'success' : 'blue'"
                                        >{{ game.game_status }}</v-chip
                                    >
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Betting</th>
                                <td>
                                    <div class="d-flex flex-wrap align-center ga-3">
                                        <v-chip
                                            size="x-small"
                                            variant="tonal"
                                            :color="
                                                game.betting === 'Enabled' ? 'success' : 'orange'
                                            "
                                            >{{ game.betting }}</v-chip
                                        >
                                        <v-switch
                                            v-if="can('Otso Panalo Game Settings')"
                                            :model-value="game.betting === 'Enabled'"
                                            :label="
                                                game.betting === 'Enabled' ? 'Disable' : 'Enable'
                                            "
                                            :aria-label="
                                                game.betting === 'Enabled'
                                                    ? 'Disable Betting'
                                                    : 'Enable Betting'
                                            "
                                            :aria-labelledby="null"
                                            role="switch"
                                            size="small"
                                            density="compact"
                                            color="success"
                                            hide-details
                                            inline
                                            :disabled="
                                                !migrationReady ||
                                                cancelled ||
                                                finished ||
                                                actionForm.processing
                                            "
                                            @click.capture.prevent.stop="requestBettingChange"
                                        />
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Ending Game ID</th>
                                <td>#{{ game.ending_game_id }}</td>
                            </tr>
                            <tr>
                                <th scope="row">Gross Bets</th>
                                <td>PHP {{ money(summary.gross) }}</td>
                            </tr>
                            <tr>
                                <th scope="row">Total Winnings</th>
                                <td>PHP {{ money(summary.winnings) }}</td>
                            </tr>
                            <tr>
                                <th scope="row">GGR</th>
                                <td class="font-weight-bold">
                                    PHP {{ money(grossGamingRevenue) }}
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-col>
                <v-col cols="12" sm="6" lg="4" tag="section">
                    <h3
                        class="otso-section-label text-primary text-uppercase font-weight-regular mb-2"
                    >
                        Prize Configuration
                    </h3>
                    <v-table
                        class="otso-details-table"
                        density="compact"
                        aria-label="Prize configuration"
                    >
                        <tbody>
                            <tr
                                v-for="field in prizeFields.filter(
                                    (field) => field.key !== 'pool_percentage',
                                )"
                                :key="field.key"
                            >
                                <th scope="row">{{ field.label }}</th>
                                <td>PHP {{ money(game[field.key]) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-col>
                <v-col cols="12" sm="6" lg="4" tag="section">
                    <template v-if="can('Ending Game PDF Generation')">
                        <h3
                            class="otso-section-label text-primary text-uppercase font-weight-regular mb-2"
                        >
                            PDF Generation
                        </h3>
                        <v-table
                            class="otso-details-table"
                            density="compact"
                            aria-label="PDF generation"
                        >
                            <tbody>
                                <tr>
                                    <th scope="row">Action</th>
                                    <td>
                                        <v-btn
                                            :href="pdf.url(game.id)"
                                            color="primary"
                                            prepend-icon="mdi-file-pdf-box"
                                            variant="outlined"
                                            size="x-small"
                                            height="26"
                                            >Generate Bets PDF</v-btn
                                        >
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Coverage</th>
                                    <td>All bets for this Otso Panalo game</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </template>
                    <template v-else>
                        <h3
                            class="otso-section-label text-primary text-uppercase font-weight-regular mb-2"
                        >
                            Bets
                        </h3>
                        <v-table
                            class="otso-details-table"
                            density="compact"
                            aria-label="Bets summary"
                            ><tbody>
                                <tr>
                                    <th scope="row">Total Bets</th>
                                    <td>{{ summary.bets }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">Refunded Amount</th>
                                    <td>PHP {{ money(summary.refunded) }}</td>
                                </tr>
                            </tbody></v-table
                        >
                    </template>
                    <div class="d-flex flex-wrap ga-2 mt-4">
                        <v-btn
                            :href="bets.url({ query: { game_id: game.id } })"
                            variant="text"
                            size="small"
                            color="primary"
                            @click.prevent="router.visit(bets({ query: { game_id: game.id } }))"
                            >View Bets ({{ summary.bets }})</v-btn
                        >
                        <v-btn
                            v-if="
                                game.legacy_id &&
                                can('Create Otso Panalo') &&
                                !finished &&
                                !cancelled
                            "
                            :disabled="!migrationReady"
                            variant="text"
                            size="small"
                            @click="ask('import')"
                            >Import Legacy Bets</v-btn
                        >
                    </div>
                </v-col>
            </v-row>
        </v-card>
        <v-alert v-if="cancelled" type="warning" variant="tonal" class="my-4"
            >This game was cancelled: {{ game.cancellation_reason?.replaceAll('_', ' ') }}.
            Cancelled at {{ displayDate(game.cancelled_at) }}.</v-alert
        >
        <v-defaults-provider
            v-if="canViewResult"
            :defaults="{
                VChip: { size: 'x-small', variant: 'tonal', rounded: 'sm' },
                VSheet: { border: true, rounded: 'lg' },
            }"
        >
            <v-card class="otso-result-card mt-5 mb-4 overflow-hidden">
                <div
                    class="otso-result-header d-flex flex-column flex-sm-row justify-space-between align-start ga-4 pa-4 pa-sm-5 border-b"
                >
                    <div>
                        <div
                            class="otso-section-label text-primary text-uppercase font-weight-regular"
                        >
                            Game Result
                        </div>
                        <h2 class="text-h6 font-weight-bold mt-2 mb-1">Create Result</h2>
                        <p class="text-caption text-blue-grey ma-0">
                            Enter one digit per team for each quarter.
                        </p>
                    </div>
                    <div class="d-flex flex-wrap justify-sm-end ga-2">
                        <v-chip
                            :color="result ? 'success' : 'orange'"
                            :prepend-icon="result ? 'mdi-check-circle-outline' : 'mdi-history'"
                            >{{ result ? 'Result Saved' : 'Pending Result' }}</v-chip
                        >
                        <v-chip
                            :color="completedQuarters === 4 ? 'success' : 'blue-grey'"
                            prepend-icon="mdi-timer-sand"
                            >{{ completedQuarters }}/4 Quarters Ready</v-chip
                        >
                    </div>
                </div>
                <div class="pa-3 pa-sm-4">
                    <v-sheet class="otso-result-summary pa-3 mb-4">
                        <v-row gap="12">
                            <v-col cols="12" sm="4"
                                ><div
                                    class="otso-result-label text-blue-grey text-uppercase font-weight-bold"
                                >
                                    Matchup
                                </div>
                                <div
                                    class="text-subtitle-2 text-blue-grey-darken-4 font-weight-bold my-1 text-break"
                                >
                                    {{ game.team_a_name }} vs {{ game.team_b_name }}
                                </div></v-col
                            >
                            <v-col cols="12" sm="4"
                                ><div
                                    class="otso-result-label text-blue-grey text-uppercase font-weight-bold"
                                >
                                    Game ID
                                </div>
                                <div
                                    class="text-subtitle-2 text-blue-grey-darken-4 font-weight-bold my-1"
                                >
                                    #{{ game.id }}
                                </div></v-col
                            >
                            <v-col cols="12" sm="4"
                                ><div
                                    class="otso-result-label text-blue-grey text-uppercase font-weight-bold"
                                >
                                    Current Snapshot
                                </div>
                                <div
                                    class="text-subtitle-2 text-blue-grey-darken-4 font-weight-bold my-1 text-break"
                                >
                                    {{ resultCombination }}
                                </div></v-col
                            >
                        </v-row>
                    </v-sheet>
                    <v-row v-if="result" gap="8" class="mb-3">
                        <v-col cols="12" sm="4"
                            ><v-sheet class="pa-3 h-100"
                                ><div
                                    class="otso-result-label text-blue-grey text-uppercase font-weight-bold"
                                >
                                    Result ID
                                </div>
                                <div
                                    class="text-body-1 text-blue-grey-darken-4 font-weight-bold my-1"
                                >
                                    #{{ result.id }}
                                </div>
                                <div class="text-caption text-blue-grey">
                                    Linked to game #{{ result.game_id }}
                                </div></v-sheet
                            ></v-col
                        >
                        <v-col cols="12" sm="4"
                            ><v-sheet class="pa-3 h-100"
                                ><div
                                    class="otso-result-label text-blue-grey text-uppercase font-weight-bold"
                                >
                                    Approval Status
                                </div>
                                <v-chip
                                    :color="result.status === 'Approved' ? 'success' : 'orange'"
                                    class="my-2"
                                    >{{ result.status }}</v-chip
                                >
                                <div class="text-caption text-blue-grey">
                                    Created {{ displayDate(result.created_at)
                                    }}<span v-if="result.approved_at">
                                        &middot; Approved
                                        {{ displayDate(result.approved_at) }}</span
                                    >
                                </div></v-sheet
                            ></v-col
                        >
                        <v-col cols="12" sm="4"
                            ><v-sheet class="otso-result-combination pa-3 h-100"
                                ><div
                                    class="otso-result-label text-blue-grey text-uppercase font-weight-bold"
                                >
                                    Result Combination
                                </div>
                                <div
                                    class="text-subtitle-2 text-blue-grey-darken-4 font-weight-bold my-1 text-break"
                                >
                                    {{ resultCombination }}
                                </div>
                                <div class="text-caption text-blue-grey">
                                    Review the per-quarter breakdown below.
                                </div></v-sheet
                            ></v-col
                        >
                    </v-row>
                    <v-sheet v-if="result" class="pa-3">
                        <div class="d-flex flex-wrap align-center justify-space-between ga-2 mb-3">
                            <div>
                                <h3 class="text-body-2 font-weight-bold ma-0">Quarter Breakdown</h3>
                                <p class="text-caption text-blue-grey ma-0">
                                    Saved quarter scores for this Otso Panalo game result.
                                </p>
                            </div>
                            <v-chip color="indigo">{{ resultCombination }}</v-chip>
                        </div>
                        <v-row gap="8"
                            ><v-col
                                v-for="quarter in quarters"
                                :key="quarter.number"
                                cols="6"
                                sm="3"
                                ><v-sheet class="pa-2" color="grey-lighten-5"
                                    ><div
                                        class="otso-result-label text-blue-grey text-uppercase font-weight-bold"
                                    >
                                        {{ quarter.label }}
                                    </div>
                                    <div
                                        class="text-body-1 text-blue-grey-darken-4 font-weight-bold my-1"
                                    >
                                        {{ result[quarter.result] || '—' }}
                                    </div></v-sheet
                                ></v-col
                            ></v-row
                        >
                    </v-sheet>
                    <v-form
                        v-else-if="!cancelled && can('Otso Panalo Create Result')"
                        @submit.prevent="saveResult"
                    >
                        <v-sheet tag="section" class="pa-3 mb-4" aria-label="Live Preview">
                            <div
                                class="d-flex flex-wrap align-center justify-space-between ga-2 mb-3"
                            >
                                <div>
                                    <h3 class="text-body-2 font-weight-bold ma-0">Live Preview</h3>
                                    <p class="text-caption text-blue-grey ma-0" aria-live="polite">
                                        {{ completedQuarters }}/4 quarters ready
                                    </p>
                                </div>
                                <div class="d-flex align-center ga-2 flex-wrap">
                                    <v-btn
                                        v-if="scores.length"
                                        variant="text"
                                        size="small"
                                        :disabled="resultForm.processing || !migrationReady"
                                        @click="useScores"
                                        >Use Last Digits of Saved Scores</v-btn
                                    >
                                    <v-chip
                                        :color="completedQuarters === 4 ? 'success' : 'blue-grey'"
                                        >{{ completionPercent }}%</v-chip
                                    >
                                </div>
                            </div>
                            <v-progress-linear
                                :model-value="completionPercent"
                                height="8"
                                color="primary"
                                bg-color="grey-lighten-2"
                                :bg-opacity="1"
                                rounded
                                class="mb-3"
                                aria-label="Quarter completion"
                            />
                            <v-row gap="8">
                                <v-col
                                    v-for="quarter in quarters"
                                    :key="quarter.number"
                                    cols="6"
                                    sm="3"
                                >
                                    <v-sheet
                                        class="pa-2"
                                        :color="
                                            quarterPreview(quarter.result) !== '--'
                                                ? 'green-lighten-5'
                                                : resultForm[quarter.result]
                                                  ? 'orange-lighten-5'
                                                  : 'grey-lighten-5'
                                        "
                                    >
                                        <div
                                            class="otso-result-label text-blue-grey text-uppercase font-weight-bold"
                                        >
                                            {{ quarter.label }}
                                        </div>
                                        <div
                                            class="text-body-1 text-blue-grey-darken-4 font-weight-bold my-1"
                                        >
                                            {{ quarterPreview(quarter.result) }}
                                        </div>
                                    </v-sheet>
                                </v-col>
                            </v-row>
                            <v-alert
                                v-if="hasPartialQuarter"
                                type="warning"
                                variant="tonal"
                                density="compact"
                                class="mt-3"
                                >Complete both digits for any quarter marked incomplete.</v-alert
                            >
                        </v-sheet>
                        <v-row gap="8">
                            <v-col
                                v-for="quarter in quarters"
                                :key="quarter.number"
                                cols="12"
                                md="6"
                            >
                                <v-sheet
                                    tag="section"
                                    class="otso-quarter-entry pa-3 pb-5 h-100"
                                    :aria-label="quarter.label"
                                >
                                    <div
                                        class="d-flex flex-wrap align-center justify-space-between ga-2 mb-3"
                                    >
                                        <div>
                                            <h3 class="text-body-2 font-weight-bold ma-0">
                                                {{ quarter.label }}
                                            </h3>
                                            <p class="text-caption text-blue-grey ma-0">
                                                Enter the last digit for both teams.
                                            </p>
                                        </div>
                                        <v-chip
                                            :color="
                                                quarterPreview(quarter.result) !== '--'
                                                    ? 'success'
                                                    : resultForm[quarter.result]
                                                      ? 'orange'
                                                      : 'blue-grey'
                                            "
                                            >{{
                                                quarterPreview(quarter.result) !== '--'
                                                    ? 'Ready'
                                                    : resultForm[quarter.result]
                                                      ? 'Incomplete'
                                                      : 'Empty'
                                            }}</v-chip
                                        >
                                    </div>
                                    <v-row gap="8" class="align-start">
                                        <v-col cols="12" sm="5">
                                            <v-text-field
                                                :model-value="resultDigit(quarter.result, 0)"
                                                :aria-label="quarter.label + ' Winning Score'"
                                                :aria-labelledby="null"
                                                :title="game.team_a_name"
                                                label="Winning Score"
                                                maxlength="1"
                                                counter="1"
                                                persistent-counter
                                                inputmode="numeric"
                                                autocomplete="off"
                                                :disabled="!migrationReady || resultForm.processing"
                                                :error-messages="resultForm.errors[quarter.result]"
                                                @update:model-value="
                                                    updateResultDigit(quarter.result, 0, $event)
                                                "
                                                @input="syncResultDigit($event, quarter.result, 0)"
                                            />
                                        </v-col>
                                        <v-col cols="12" sm="2" class="text-center pt-sm-2"
                                            ><v-icon
                                                icon="mdi-close"
                                                size="22"
                                                color="blue-grey-lighten-1"
                                                aria-hidden="true"
                                        /></v-col>
                                        <v-col cols="12" sm="5">
                                            <v-text-field
                                                :model-value="resultDigit(quarter.result, 1)"
                                                :aria-label="quarter.label + ' Losing Score'"
                                                :aria-labelledby="null"
                                                :title="game.team_b_name"
                                                label="Losing Score"
                                                maxlength="1"
                                                counter="1"
                                                persistent-counter
                                                inputmode="numeric"
                                                autocomplete="off"
                                                :disabled="!migrationReady || resultForm.processing"
                                                @update:model-value="
                                                    updateResultDigit(quarter.result, 1, $event)
                                                "
                                                @input="syncResultDigit($event, quarter.result, 1)"
                                            />
                                        </v-col>
                                    </v-row>
                                    <div
                                        class="d-flex align-center justify-space-between ga-3 mt-1"
                                    >
                                        <span class="text-caption text-blue-grey"
                                            >Formatted result</span
                                        ><v-chip
                                            color="indigo"
                                            class="font-weight-bold"
                                            :aria-label="quarter.label + ' formatted result'"
                                            >{{ quarterPreview(quarter.result) }}</v-chip
                                        >
                                    </div>
                                </v-sheet>
                            </v-col>
                        </v-row>
                        <v-row gap="8" class="mt-2">
                            <v-col cols="12" sm="6"
                                ><v-btn
                                    type="submit"
                                    color="primary"
                                    prepend-icon="mdi-content-save-outline"
                                    height="42"
                                    block
                                    :disabled="!migrationReady"
                                    :loading="resultForm.processing"
                                    >Save Result</v-btn
                                ></v-col
                            >
                            <v-col cols="12" sm="6"
                                ><v-btn
                                    type="button"
                                    variant="outlined"
                                    color="black"
                                    prepend-icon="mdi-refresh"
                                    height="42"
                                    block
                                    :disabled="!migrationReady || resultForm.processing"
                                    @click="clearResultInputs"
                                    >Clear Inputs</v-btn
                                ></v-col
                            >
                        </v-row>
                    </v-form>
                    <p v-else-if="!result" class="text-caption text-blue-grey">
                        No result submitted.
                    </p>
                    <v-row v-if="result?.status === 'For Approval'" gap="12" class="mt-4">
                        <v-col v-if="can('Otso Panalo Update Result')" cols="12" sm="6"
                            ><v-btn
                                color="primary"
                                variant="outlined"
                                :disabled="!migrationReady || cancelled"
                                prepend-icon="mdi-pencil-outline"
                                height="42"
                                block
                                @click="resultDialog = true"
                                >Edit Result</v-btn
                            ></v-col
                        >
                        <v-col v-if="can('Otso Panalo Approve Result')" cols="12" sm="6"
                            ><v-btn
                                color="success"
                                :disabled="!migrationReady || cancelled"
                                prepend-icon="mdi-check-circle-outline"
                                height="42"
                                block
                                @click="ask('approveResult')"
                                >Approve Result</v-btn
                            ></v-col
                        >
                    </v-row>
                </div>
            </v-card>
        </v-defaults-provider>
        <v-card class="mt-4"
            ><v-tabs v-model="tab" color="primary"
                ><v-tab v-if="winners" value="winners">Winners</v-tab
                ><v-tab value="activity">Activity</v-tab></v-tabs
            >
            <v-tabs-window v-model="tab">
                <v-tabs-window-item v-if="winners" value="winners"
                    ><div class="pa-4">
                        <div class="d-flex ga-3 align-center flex-wrap mb-4">
                            <h2>Otso Panalo Winners</h2>
                            <v-spacer /><v-btn
                                v-if="!game.winners_generated_at && !cancelled"
                                color="primary"
                                :disabled="!migrationReady || resultStatus !== 'Approved'"
                                @click="ask('generate')"
                                >Generate Winners</v-btn
                            ><v-btn
                                v-if="
                                    can('Otso Panalo Game Winner Approval') &&
                                    winners.total > 0 &&
                                    !cancelled
                                "
                                variant="outlined"
                                :disabled="!migrationReady"
                                @click="ask('approveWinners')"
                                >Approve All</v-btn
                            >
                        </div>
                        <v-alert
                            v-if="resultStatus !== 'Approved'"
                            type="info"
                            variant="tonal"
                            class="mb-4"
                            >Approve the game result before generating winners.</v-alert
                        >
                        <v-table class="ending-table"
                            ><thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Player</th>
                                    <th>Winner Type</th>
                                    <th>Combinations</th>
                                    <th>Prize Amount</th>
                                    <th>Status</th>
                                    <th>Prize Released</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="winner in winners.data" :key="winner.id">
                                    <td>#{{ winner.bet_id }} {{ winner.ticket_name }}</td>
                                    <td>
                                        {{
                                            winner.username ||
                                            winner.player_name ||
                                            'Unlinked player'
                                        }}
                                    </td>
                                    <td>{{ winner.winner_type }}</td>
                                    <td>
                                        <div v-for="quarter in quarters" :key="quarter.number">
                                            Q{{ quarter.number }}:
                                            {{ winner[quarter.combination] || '—' }}
                                        </div>
                                    </td>
                                    <td>PHP {{ money(winner.prize_amount) }}</td>
                                    <td>
                                        <v-chip
                                            size="small"
                                            :color="
                                                winner.winner_status === 'Approved'
                                                    ? 'success'
                                                    : 'warning'
                                            "
                                            >{{ winner.winner_status }}</v-chip
                                        >
                                    </td>
                                    <td>{{ winner.prize_released }}</td>
                                    <td>
                                        <v-btn
                                            v-if="
                                                winner.winner_status === 'Verified' &&
                                                can('Otso Panalo Game Winner Approval')
                                            "
                                            variant="outlined"
                                            size="small"
                                            :disabled="!migrationReady || cancelled"
                                            @click="ask('approveWinners', winner.id)"
                                            >Approve</v-btn
                                        >
                                    </td>
                                </tr>
                                <tr v-if="!winners.data.length">
                                    <td colspan="8" class="text-center pa-6">
                                        {{
                                            game.winners_generated_at
                                                ? 'No winners matched the approved result.'
                                                : 'Winners have not been generated.'
                                        }}
                                    </td>
                                </tr>
                            </tbody></v-table
                        ><EndingPager :page="winners" /></div
                ></v-tabs-window-item>
                <v-tabs-window-item value="activity"
                    ><div class="pa-4">
                        <h2 class="mb-3">Activity History</h2>
                        <v-table class="ending-table"
                            ><thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Administrator</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="audit in audits" :key="audit.id">
                                    <td>{{ audit.action.replaceAll('_', ' ') }}</td>
                                    <td>{{ audit.actor || 'Unavailable' }}</td>
                                    <td>{{ displayDate(audit.created_at) }}</td>
                                </tr>
                            </tbody></v-table
                        >
                        <h3 class="mt-4 mb-3">Legacy Activity</h3>
                        <v-table class="ending-table"
                            ><thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>Administrator</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="log in legacyLogs" :key="log.id">
                                    <td>{{ log.action.replaceAll('_', ' ') }}</td>
                                    <td>{{ log.details }}</td>
                                    <td>{{ log.actor || 'Historical administrator' }}</td>
                                    <td>{{ displayDate(log.created_at) }}</td>
                                </tr>
                            </tbody></v-table
                        >
                    </div></v-tabs-window-item
                >
            </v-tabs-window>
        </v-card>
        <v-dialog v-model="edit" max-width="760"
            ><GameForm
                v-if="edit"
                :game="game"
                :available-games="availableGames"
                :active-config="activeConfig"
                @close="edit = false"
                @saved="edit = false"
        /></v-dialog>
        <v-dialog v-model="resultDialog" max-width="700" :persistent="resultForm.processing"
            ><v-card class="ending-dialog"
                ><v-card-title>{{ result ? 'Edit Result' : 'Create Result' }}</v-card-title
                ><v-form @submit.prevent="saveResult"
                    ><v-card-text
                        ><p class="mb-4">
                            Enter each quarter as one digit for team A, a hyphen, and one digit for
                            team B (for example, 1-2). At least one quarter is required.
                        </p>
                        <v-btn
                            v-if="scores.length"
                            class="mb-4"
                            variant="outlined"
                            size="small"
                            @click="useScores"
                            >Use Last Digits of Saved Scores</v-btn
                        ><v-alert v-if="page.props.errors.action" type="error" class="mb-4">{{
                            page.props.errors.action
                        }}</v-alert
                        ><v-text-field
                            v-for="quarter in quarters"
                            :key="quarter.number"
                            v-model="resultForm[quarter.result]"
                            :label="
                                quarter.label + ' · ' + game.team_a_name + '–' + game.team_b_name
                            "
                            placeholder="0-0"
                            maxlength="3"
                            :error-messages="resultForm.errors[quarter.result]" /></v-card-text
                    ><v-card-actions class="ending-dialog-actions"
                        ><v-btn :disabled="resultForm.processing" @click="resultDialog = false"
                            >Cancel</v-btn
                        ><v-btn color="primary" type="submit" :loading="resultForm.processing">{{
                            result ? 'Save Result' : 'Submit for Approval'
                        }}</v-btn></v-card-actions
                    ></v-form
                ></v-card
            ></v-dialog
        >
        <v-dialog
            :model-value="!!pending"
            max-width="560"
            scrollable
            :persistent="actionForm.processing"
            aria-labelledby="otso-confirm-title"
            aria-describedby="otso-confirm-description otso-confirm-notice"
            @update:model-value="pending = null"
        >
            <v-card
                v-if="confirmation"
                class="otso-confirm-dialog"
                rounded="xl"
                :style="{ '--otso-confirm-color': `var(--v-theme-${confirmation.color})` }"
                :aria-busy="actionForm.processing"
            >
                <div class="otso-confirm-header pa-5 pa-sm-6 pb-0 pb-sm-0">
                    <v-avatar
                        :color="confirmation.color"
                        variant="tonal"
                        rounded="lg"
                        size="48"
                        class="flex-shrink-0"
                    >
                        <v-icon :icon="confirmation.icon" size="26" />
                    </v-avatar>
                    <div class="otso-confirm-heading">
                        <p class="otso-confirm-eyebrow text-medium-emphasis ma-0 mb-1">
                            Otso Panalo
                        </p>
                        <h2 id="otso-confirm-title" class="otso-confirm-title ma-0">
                            {{ confirmation.title }}
                        </h2>
                    </div>
                    <v-btn
                        icon="mdi-close"
                        variant="text"
                        size="small"
                        color="secondary"
                        aria-label="Close confirmation"
                        :disabled="actionForm.processing"
                        @click="pending = null"
                    />
                </div>
                <v-card-text class="otso-confirm-body pa-5 pa-sm-6">
                    <p id="otso-confirm-description" class="otso-confirm-description ma-0 mb-5">
                        {{ confirmation.description }}
                    </p>
                    <section class="otso-confirm-context pa-4" aria-label="Game to confirm">
                        <p class="otso-confirm-eyebrow text-medium-emphasis ma-0 mb-1">
                            Game #{{ game.id }}
                        </p>
                        <p class="otso-confirm-matchup ma-0">
                            {{ game.team_a_name }}
                            <span class="text-medium-emphasis font-weight-regular">vs</span>
                            {{ game.team_b_name }}
                        </p>
                        <p class="text-body-small text-medium-emphasis ma-0 mt-1">
                            {{ displayDate(game.schedule) }}
                        </p>
                        <div
                            v-if="pending === 'approveResult' || pending === 'generate'"
                            class="otso-confirm-quarters mt-4"
                            role="group"
                            aria-label="Quarter results"
                        >
                            <div
                                v-for="quarter in quarters"
                                :key="quarter.number"
                                class="otso-confirm-quarter pa-2 text-center"
                            >
                                <p class="otso-confirm-eyebrow text-medium-emphasis ma-0">
                                    Q{{ quarter.number }}
                                </p>
                                <p class="otso-confirm-score ma-0">
                                    {{ result?.[quarter.result] || '—' }}
                                </p>
                            </div>
                        </div>
                        <dl
                            v-if="pending === 'approveWinners' && selectedWinner"
                            class="otso-confirm-winner mt-4 pt-3"
                        >
                            <div>
                                <dt>Ticket</dt>
                                <dd>
                                    #{{ selectedWinner.bet_id }} {{ selectedWinner.ticket_name }}
                                </dd>
                            </div>
                            <div>
                                <dt>Player</dt>
                                <dd>
                                    {{
                                        selectedWinner.username ||
                                        selectedWinner.player_name ||
                                        'Unlinked player'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt>{{ selectedWinner.winner_type }}</dt>
                                <dd class="font-weight-bold">
                                    PHP {{ money(selectedWinner.prize_amount) }}
                                </dd>
                            </div>
                        </dl>
                    </section>
                    <v-select
                        v-if="pending === 'cancel'"
                        v-model="actionForm.reason"
                        class="mt-4"
                        label="Cancellation Reason"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        color="error"
                        :disabled="actionForm.processing"
                        :error-messages="actionForm.errors.reason"
                        :items="[
                            { title: 'Game cancelled', value: 'cancelled' },
                            {
                                title: 'Postponed beyond 48 hours',
                                value: 'postponed_beyond_48_hours',
                            },
                            {
                                title: 'Official results cannot be determined',
                                value: 'official_results_cannot_be_determined',
                            },
                            {
                                title: 'Started before close of betting',
                                value: 'started_before_close_of_betting',
                            },
                        ]"
                    />
                    <div class="otso-confirm-notice d-flex align-start ga-3 pa-3 mt-4">
                        <v-icon
                            :icon="confirmation.noticeIcon"
                            :color="confirmation.color"
                            size="20"
                            class="mt-1 flex-shrink-0"
                        />
                        <p id="otso-confirm-notice" class="ma-0">{{ confirmation.notice }}</p>
                    </div>
                    <v-alert
                        v-if="page.props.errors.action"
                        type="error"
                        variant="tonal"
                        rounded="lg"
                        class="mt-4"
                    >
                        {{ page.props.errors.action }}
                    </v-alert>
                </v-card-text>
                <v-divider />
                <v-card-actions class="otso-confirm-actions pa-4 px-sm-6">
                    <v-btn
                        variant="outlined"
                        color="secondary"
                        rounded="lg"
                        height="44"
                        :disabled="actionForm.processing"
                        @click="pending = null"
                    >
                        {{ pending === 'cancel' ? 'Keep Game' : 'Back' }}
                    </v-btn>
                    <v-btn
                        :color="confirmationButtonColors[confirmation.color]"
                        :prepend-icon="confirmation.icon"
                        variant="flat"
                        rounded="lg"
                        height="44"
                        :loading="actionForm.processing"
                        :disabled="actionForm.processing"
                        @click="confirm"
                    >
                        {{ confirmation.label }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </OtsoShell>
</template>

<style scoped>
.otso-game-actions {
    position: absolute;
    top: 0;
    right: 0;
}
.otso-matchup-team {
    width: 110px;
    font-size: 10px;
}
.otso-matchup-team :deep(.ending-logo-fallback) {
    background: transparent;
    font-size: 24px;
}
.otso-section-label {
    font-size: 10px;
    line-height: 1.8;
    letter-spacing: 1.25px;
}
.otso-details-table :deep(table) {
    table-layout: fixed;
}
.otso-details-table :deep(th),
.otso-details-table :deep(td) {
    height: 26px !important;
    padding: 2px 16px !important;
    font-size: 12px;
    overflow-wrap: anywhere;
}
.otso-details-table :deep(th) {
    width: 38%;
    color: #9e9e9e;
    font-weight: 400;
}
.otso-details-table :deep(.v-selection-control) {
    min-height: 22px;
}
.otso-result-header {
    background: linear-gradient(135deg, #f7fbff 0%, #eef6ff 45%, #f8fbf4 100%);
}
.otso-result-summary {
    background: linear-gradient(135deg, #fff 0%, #f9fbff 100%);
}
.otso-result-combination {
    background: linear-gradient(135deg, #fffdf6 0%, #fff7dd 100%);
}
.otso-quarter-entry {
    background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
}
.otso-result-card :deep(.v-sheet) {
    border-color: #e1eaf5 !important;
}
.otso-result-label {
    font-size: 10px;
    letter-spacing: 0.08em;
}
.otso-quarter-entry :deep(.v-field__input),
.otso-quarter-entry :deep(.v-label) {
    font-size: 14px;
}
.otso-quarter-entry :deep(.v-field__input) {
    min-height: 40px;
    padding-block: 8px;
}
.otso-quarter-entry :deep(.v-input__details) {
    min-height: 18px;
    margin-top: 3px;
    font-size: 11px;
}
.otso-confirm-dialog {
    --v-font-body: Poppins, sans-serif;
    --v-font-heading: var(--v-font-body);
    border-top: 4px solid rgb(var(--otso-confirm-color));
    border-radius: 20px !important;
    box-shadow: 0 24px 64px rgb(11 11 28 / 18%) !important;
}
.otso-confirm-winner dd {
    margin: 0;
}
.otso-confirm-dialog :deep(.v-label) {
    color: rgba(var(--v-theme-on-surface), 0.7);
    opacity: 1;
}
.otso-confirm-dialog :deep(.v-field) {
    border-radius: 10px;
}
.otso-confirm-header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    flex-shrink: 0;
}
.otso-confirm-heading {
    flex: 1;
    min-width: 0;
}
.otso-confirm-eyebrow {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.1em;
    line-height: 1.6;
    text-transform: uppercase;
}
.otso-confirm-title {
    font-size: 22px;
    font-weight: 600;
    line-height: 1.3;
    overflow-wrap: anywhere;
}
.otso-confirm-description {
    color: rgba(var(--v-theme-on-surface), 0.7);
    font-size: 14px;
    line-height: 1.7;
}
.otso-confirm-body {
    overscroll-behavior: contain;
}
.otso-confirm-context {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 12px;
    background: rgb(var(--v-theme-background));
}
.otso-confirm-matchup {
    font-size: 14px;
    font-weight: 600;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.otso-confirm-quarters {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
}
.otso-confirm-quarter {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 8px;
    background: rgb(var(--v-theme-surface));
}
.otso-confirm-score {
    font-size: 20px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    line-height: 1.6;
    white-space: nowrap;
}
.otso-confirm-winner {
    display: grid;
    gap: 8px;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    font-size: 12px;
}
.otso-confirm-winner > div {
    display: flex;
    justify-content: space-between;
    gap: 16px;
}
.otso-confirm-winner dt {
    color: rgba(var(--v-theme-on-surface), 0.6);
}
.otso-confirm-winner dd {
    text-align: right;
    overflow-wrap: anywhere;
    min-width: 0;
}
.otso-confirm-notice {
    border: 1px solid rgba(var(--otso-confirm-color), 0.18);
    border-radius: 10px;
    background: rgba(var(--otso-confirm-color), 0.06);
    font-size: 12px;
    line-height: 1.7;
}
.otso-confirm-actions {
    justify-content: flex-end;
    gap: 12px;
    flex-shrink: 0;
}
.otso-confirm-actions > .v-btn {
    margin: 0;
    padding-inline: 18px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
}
@media (max-width: 599px) {
    .otso-game-actions {
        position: static;
    }
    .otso-confirm-header {
        gap: 12px;
    }
    .otso-confirm-title {
        font-size: 20px;
    }
    .otso-confirm-score {
        font-size: 16px;
    }
    .otso-confirm-actions {
        flex-direction: column-reverse;
        align-items: stretch;
        gap: 10px;
    }
    .otso-confirm-actions > .v-btn {
        width: 100%;
    }
}
</style>
