<script setup lang="ts">
import { index } from '@/actions/App/Http/Controllers/OtsoPromoAnalyticsController';
import AdminDataTable from '@/components/AdminDataTable.vue';
import OtsoShell from '@/components/otso/OtsoShell.vue';
import { exportTableItems } from '@/composables/useTableExport';
import { money } from '@/types/ending';
import { displayOtsoDate } from '@/types/otso';
import type { PromoFilters, PromoReport } from '@/types/otso-analytics';
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{ filters: PromoFilters; report: PromoReport | null }>();
const filters = reactive({ ...props.filters });
const loading = ref(false);
const errors = ref<Record<string, string>>({});
watch(
    () => props.filters,
    (value) => Object.assign(filters, value),
);
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
const count = (value: number) => new Intl.NumberFormat('en-PH').format(value);
const percent = (value: number | null) => (value === null ? '—' : `${value.toFixed(1)}%`);
const ratio = (part: number, total: number) => (total ? (100 * part) / total : null);
const duration = (value: number | null) => (value === null ? '—' : `${(value / 1000).toFixed(1)}s`);
const label = (value: string | null) => (value ? value.replaceAll('_', ' ') : '—');
const sourceLabel = (value: string) =>
    value === 'login_promo'
        ? 'Login promo'
        : value === 'game_banner'
          ? 'Game banner'
          : label(value);
const tutorialTotals = computed(() =>
    (props.report?.tutorials ?? []).reduce(
        (totals, row) => ({
            opened: totals.opened + row.opened,
            completed: totals.completed + row.completed,
        }),
        { opened: 0, completed: 0 },
    ),
);
const retentionTotals = computed(() =>
    [1, 7, 30].map((day) => {
        const rows = (props.report?.retention ?? []).filter((row) => row.day === day);
        return {
            day,
            eligible: rows.reduce((sum, row) => sum + row.eligible_players, 0),
            portal: rows.reduce((sum, row) => sum + row.portal_returned, 0),
            otso: rows.reduce((sum, row) => sum + row.otso_returned, 0),
        };
    }),
);
const chartMaximum = computed(() =>
    Math.max(1, ...(props.report?.daily ?? []).map((day) => day.impressions)),
);
const journey = computed(() => {
    const summary = props.report?.summary;
    return summary
        ? [
              { title: 'Promo shown', value: summary.impressions, icon: 'mdi-eye-outline' },
              {
                  title: 'Play clicked',
                  value: summary.played,
                  icon: 'mdi-cursor-default-click-outline',
              },
              { title: 'Game page reached', value: summary.arrived, icon: 'mdi-basketball' },
              {
                  title: 'Bet submitted',
                  value: summary.submitted,
                  icon: 'mdi-ticket-confirmation-outline',
              },
          ]
        : [];
});
const engagementHeaders = [
    { title: 'Step', key: 'dialog_step' },
    { title: 'Device', key: 'device_type' },
    { title: 'Source route', key: 'source_route' },
    { title: 'Impressions', key: 'impressions' },
    { title: 'Players', key: 'exposed_players' },
    { title: 'Play clicks', key: 'played' },
    { title: 'Play CTR', key: 'ctr' },
    { title: 'Close rate', key: 'close_rate' },
    { title: 'Interrupted', key: 'interrupted' },
    { title: 'Unknown outcome', key: 'unknown' },
];
const dailyHeaders = [
    { title: 'Date (Manila)', key: 'date' },
    { title: 'Impressions', key: 'impressions' },
    { title: 'Players', key: 'exposed_players' },
    { title: 'Play clicks', key: 'played' },
    { title: 'Play CTR', key: 'ctr' },
    { title: 'Closes', key: 'closed' },
    { title: 'Game arrivals', key: 'arrived' },
    { title: 'Unknown outcome', key: 'unknown' },
];
const retentionHeaders = [
    { title: 'First exposure (Manila)', key: 'cohort_date' },
    { title: 'Return day', key: 'day' },
    { title: 'Cohort players', key: 'cohort_players' },
    { title: 'Eligible players', key: 'eligible_players' },
    { title: 'Portal returns', key: 'portal_returned' },
    { title: 'Portal retention', key: 'portal_rate' },
    { title: 'Otso returns', key: 'otso_returned' },
    { title: 'Otso retention', key: 'otso_rate' },
];
const diagnosticHeaders = [
    { title: 'Event', key: 'event_name' },
    { title: 'Source', key: 'source' },
    { title: 'Reason', key: 'reason' },
    { title: 'HTTP status', key: 'http_status' },
    { title: 'Events', key: 'events' },
    { title: 'Players', key: 'players' },
];

function load() {
    router.get(
        index.url(),
        { ...filters, source_route: filters.source_route || null },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                loading.value = true;
                errors.value = {};
            },
            onError: (value) => {
                errors.value = value;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}
function preset(days: number) {
    const from = new Date(`${today}T00:00:00Z`);
    from.setUTCDate(from.getUTCDate() - days + 1);
    filters.date_from = from.toISOString().slice(0, 10);
    filters.date_to = today;
    load();
}
function exportSummary() {
    if (!props.report) return;
    const row = {
        ...props.filters,
        ...props.report.summary,
        ...props.report.conversion,
        tutorial_attempts: tutorialTotals.value.opened,
        tutorial_completions: tutorialTotals.value.completed,
        generated_at: props.report.generated_at,
    };
    exportTableItems(
        'otso-promo-summary.csv',
        [row],
        Object.keys(row).map((key) => ({ key, title: label(key) })),
    );
}
</script>

<template>
    <OtsoShell title="Promo Analytics" :migration-ready="true">
        <div class="promo-analytics" :aria-busy="loading">
            <div class="analytics-intro">
                <div>
                    <p class="intro-copy">
                        Understand how players respond to the login promotion, learn the game, and
                        start playing.
                    </p>
                    <p class="analytics-note">
                        All dates use Asia/Manila. Promo actions use a 24-hour exposure window.
                    </p>
                </div>
                <div class="intro-actions">
                    <v-btn
                        variant="outlined"
                        prepend-icon="mdi-refresh"
                        :loading="loading"
                        @click="load"
                        >Refresh</v-btn
                    >
                    <v-btn
                        color="primary"
                        prepend-icon="mdi-download"
                        :disabled="!report || loading"
                        @click="exportSummary"
                        >Export summary</v-btn
                    >
                </div>
            </div>

            <v-card class="analytics-panel filter-panel">
                <div class="range-presets" aria-label="Date presets">
                    <span class="filter-label">Reporting period</span>
                    <v-btn
                        v-for="days in [7, 30, 90]"
                        :key="days"
                        size="small"
                        variant="tonal"
                        :disabled="loading"
                        @click="preset(days)"
                        >Last {{ days }} days</v-btn
                    >
                    <span class="analytics-note">Up to 93 days</span>
                </div>
                <form class="filter-grid" @submit.prevent="load">
                    <v-text-field
                        v-model="filters.date_from"
                        label="From"
                        type="date"
                        :max="today"
                        :error-messages="errors.date_from"
                    />
                    <v-text-field
                        v-model="filters.date_to"
                        label="To (inclusive)"
                        type="date"
                        :min="filters.date_from"
                        :max="today"
                        :error-messages="errors.date_to"
                    />
                    <v-select
                        v-model="filters.dialog_step"
                        label="Dialog step"
                        :items="[
                            { title: 'Both steps', value: null },
                            { title: 'Step 1', value: 1 },
                            { title: 'Step 2', value: 2 },
                        ]"
                        item-title="title"
                        item-value="value"
                        :error-messages="errors.dialog_step"
                    />
                    <v-select
                        v-model="filters.device_type"
                        label="Device"
                        :items="[
                            { title: 'All devices', value: null },
                            { title: 'Mobile', value: 'mobile' },
                            { title: 'Tablet', value: 'tablet' },
                            { title: 'Desktop', value: 'desktop' },
                        ]"
                        item-title="title"
                        item-value="value"
                        :error-messages="errors.device_type"
                    />
                    <v-text-field
                        v-model="filters.source_route"
                        label="Source route (optional)"
                        placeholder="e.g. Home"
                        clearable
                        :error-messages="errors.source_route"
                    />
                    <v-btn type="submit" color="primary" :loading="loading">Apply filters</v-btn>
                </form>
            </v-card>
            <v-progress-linear
                v-if="loading"
                indeterminate
                color="primary"
                class="mb-4"
                aria-label="Loading analytics"
            />
            <v-alert v-if="!report" type="warning" variant="tonal"
                >Promo analytics are currently unavailable. Try refreshing or ask your administrator
                to check the analytics data connection.</v-alert
            >

            <template v-else>
                <v-alert v-if="report.quality.events === 0" type="info" variant="tonal" class="mb-4"
                    >No promo events match this period and these filters. Choose a different period
                    or clear the step, device, and route filters.</v-alert
                >
                <v-alert
                    v-if="report.attribution_pending"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-4"
                    >Recent results are still developing. Players can act for 24 hours after
                    exposure, and events may arrive late.</v-alert
                >
                <div class="metric-grid">
                    <v-card class="metric-card">
                        <span class="metric-label">Players reached</span
                        ><strong>{{ count(report.summary.exposed_players) }}</strong>
                        <span>{{ report.summary.frequency ?? '—' }} impressions per player</span>
                    </v-card>
                    <v-card class="metric-card">
                        <span class="metric-label">Promo impressions</span
                        ><strong>{{ count(report.summary.impressions) }}</strong>
                        <span>{{ count(report.summary.unknown) }} with unknown outcome</span>
                    </v-card>
                    <v-card class="metric-card accent-card">
                        <span class="metric-label">Play click rate</span
                        ><strong>{{ percent(report.summary.ctr) }}</strong>
                        <span
                            >{{ count(report.summary.played) }} impressions clicked ·
                            {{ count(report.summary.clicking_players) }} players</span
                        >
                    </v-card>
                    <v-card class="metric-card">
                        <span class="metric-label">Player bet conversion</span
                        ><strong>{{
                            percent(
                                report.conversion
                                    ? ratio(
                                          report.conversion.players,
                                          report.summary.exposed_players,
                                      )
                                    : null,
                            )
                        }}</strong>
                        <span>{{
                            report.conversion
                                ? `${count(report.conversion.players)} players · ${count(report.conversion.first_time_bettors)} first-time bettors`
                                : 'Bet records unavailable'
                        }}</span>
                    </v-card>
                    <v-card class="metric-card">
                        <span class="metric-label">Tutorial completion</span
                        ><strong>{{
                            percent(ratio(tutorialTotals.completed, tutorialTotals.opened))
                        }}</strong>
                        <span
                            >{{ count(tutorialTotals.completed) }} of
                            {{ count(tutorialTotals.opened) }} attempts completed</span
                        >
                    </v-card>
                    <v-card class="metric-card">
                        <span class="metric-label">Attributed gross bets</span
                        ><strong>{{
                            report.conversion ? money(report.conversion.gross_amount) : '—'
                        }}</strong>
                        <span>{{
                            report.conversion
                                ? `${count(report.conversion.bets)} bets · ${money(report.conversion.refunded_amount)} refunded`
                                : 'Bet records unavailable'
                        }}</span>
                    </v-card>
                </div>

                <v-card class="analytics-panel">
                    <div class="panel-heading">
                        <div>
                            <h2>Daily engagement</h2>
                            <p>
                                Impressions and play clicks, grouped by the day the promo was shown.
                            </p>
                        </div>
                        <div class="chart-legend">
                            <span><i class="legend-impressions" /> Impressions</span
                            ><span><i class="legend-clicks" /> Play clicks</span>
                        </div>
                    </div>
                    <div
                        class="trend-scroll"
                        role="region"
                        aria-label="Daily engagement chart. Exact values are available in the daily data table below."
                        tabindex="0"
                    >
                        <div
                            class="trend-chart"
                            :style="{ minWidth: `${Math.max(420, report.daily.length * 26)}px` }"
                        >
                            <div
                                v-for="(day, position) in report.daily"
                                :key="day.date"
                                class="trend-day"
                                :title="`${day.date}: ${count(day.impressions)} impressions, ${count(day.played)} play clicks`"
                            >
                                <div class="trend-bars">
                                    <div
                                        class="impression-bar"
                                        :style="{
                                            height: `${(100 * day.impressions) / chartMaximum}%`,
                                        }"
                                    />
                                    <div
                                        class="click-bar"
                                        :style="{ height: `${(100 * day.played) / chartMaximum}%` }"
                                    />
                                </div>
                                <span>{{
                                    position % Math.max(1, Math.ceil(report.daily.length / 12)) ===
                                    0
                                        ? day.date.slice(5)
                                        : ''
                                }}</span>
                            </div>
                        </div>
                    </div>
                </v-card>

                <div class="analysis-columns">
                    <v-card class="analytics-panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Player journey</h2>
                                <p>
                                    Each count is an impression with this action. Players may skip
                                    steps.
                                </p>
                            </div>
                        </div>
                        <div v-for="stage in journey" :key="stage.title" class="journey-row">
                            <v-icon :icon="stage.icon" size="20" color="primary" />
                            <div class="journey-detail">
                                <div>
                                    <span>{{ stage.title }}</span
                                    ><strong>{{ count(stage.value) }}</strong>
                                </div>
                                <v-progress-linear
                                    :model-value="
                                        ratio(stage.value, report.summary.impressions) ?? 0
                                    "
                                    height="7"
                                    rounded
                                    color="primary"
                                />
                            </div>
                        </div>
                        <p class="analytics-note mt-5">
                            Tutorials are optional. Game arrivals can occur without a Play click,
                            and a player already on the game page may click Play without a new
                            arrival.
                        </p>
                    </v-card>
                    <v-card class="analytics-panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Dialog performance</h2>
                                <p>Compare the two presentations independently.</p>
                            </div>
                        </div>
                        <div v-if="!report.steps.length" class="empty-panel">
                            No dialog impressions in this selection.
                        </div>
                        <div v-for="step in report.steps" :key="step.dialog_step" class="step-row">
                            <div class="step-title">
                                <v-chip color="primary" variant="tonal" size="small"
                                    >Step {{ step.dialog_step }}</v-chip
                                ><span
                                    >{{ count(step.impressions) }} impressions ·
                                    {{ count(step.exposed_players) }} players</span
                                >
                            </div>
                            <div class="step-metrics">
                                <div>
                                    <strong>{{ percent(step.ctr) }}</strong
                                    ><span>Play CTR</span>
                                </div>
                                <div>
                                    <strong>{{ percent(step.close_rate) }}</strong
                                    ><span>Explicit close rate</span>
                                </div>
                                <div>
                                    <strong>{{ duration(step.average_foreground_ms) }}</strong
                                    ><span>Avg. foreground time</span>
                                </div>
                            </div>
                            <p class="analytics-note">
                                {{ count(step.closed) }} closes ·
                                {{ count(step.interrupted) }} interruptions ·
                                {{ count(step.unknown) }} unknown outcomes
                            </p>
                        </div>
                        <p class="analytics-note">
                            Closing step 1 advances to step 2. Missing terminal events remain
                            unknown.
                        </p>
                    </v-card>
                </div>

                <v-card class="analytics-panel">
                    <div class="panel-heading">
                        <div>
                            <h2>Tutorial engagement</h2>
                            <p>
                                Slide reach counts each attempt once per slide. Completion requires
                                Done on the final slide.
                            </p>
                        </div>
                    </div>
                    <div v-if="!report.tutorials.length" class="empty-panel">
                        No tutorial attempts started in this selection.
                    </div>
                    <div class="tutorial-grid">
                        <div
                            v-for="tutorial in report.tutorials"
                            :key="tutorial.source"
                            class="tutorial-card"
                        >
                            <div class="step-title">
                                <h3>{{ sourceLabel(tutorial.source) }}</h3>
                                <v-chip color="primary" variant="tonal" size="small"
                                    >{{ percent(tutorial.completion_rate) }} completed</v-chip
                                >
                            </div>
                            <p class="analytics-note">
                                {{ count(tutorial.opened) }} opened ·
                                {{ count(tutorial.completed) }} completed ·
                                {{ count(tutorial.dismissed) }} dismissed ·
                                {{ count(tutorial.interrupted) }} interrupted
                            </p>
                            <div
                                v-for="(reached, slide) in tutorial.slides"
                                :key="slide"
                                class="slide-row"
                            >
                                <span>Slide {{ slide + 1 }}</span
                                ><v-progress-linear
                                    :model-value="ratio(reached, tutorial.opened) ?? 0"
                                    height="9"
                                    rounded
                                    color="primary"
                                /><strong>{{ count(reached) }}</strong
                                ><span>{{ percent(ratio(reached, tutorial.opened)) }}</span>
                            </div>
                        </div>
                    </div>
                </v-card>

                <v-card class="analytics-panel">
                    <div class="panel-heading">
                        <div>
                            <h2>Player retention</h2>
                            <p>
                                Return on the exact Manila calendar day after a player's first
                                recorded campaign exposure.
                            </p>
                        </div>
                    </div>
                    <div class="retention-grid">
                        <div
                            v-for="retention in retentionTotals"
                            :key="retention.day"
                            class="retention-card"
                        >
                            <h3>Day {{ retention.day }}</h3>
                            <div class="retention-rates">
                                <div>
                                    <strong>{{
                                        percent(ratio(retention.portal, retention.eligible))
                                    }}</strong
                                    ><span>Portal return</span>
                                </div>
                                <div>
                                    <strong>{{
                                        percent(ratio(retention.otso, retention.eligible))
                                    }}</strong
                                    ><span>Otso return</span>
                                </div>
                            </div>
                            <p class="analytics-note">
                                {{ count(retention.eligible) }} eligible players{{
                                    retention.eligible === 0 ? ' · awaiting mature cohorts' : ''
                                }}
                            </p>
                        </div>
                    </div>
                    <p class="analytics-note mt-4">
                        Only fully elapsed return days are eligible. — means no eligible players.
                        Filters select the first exposure; later return visits can be on any device
                        or route.
                    </p>
                </v-card>

                <v-card class="analytics-panel table-panel"
                    ><AdminDataTable
                        title="Performance by step, device and route"
                        :headers="engagementHeaders"
                        :items="report.segments"
                        export-name="otso-promo-segments"
                        :loading="loading"
                        ><template #item.ctr="{ item }">{{ percent(item.ctr) }}</template
                        ><template #item.close_rate="{ item }">{{
                            percent(item.close_rate)
                        }}</template
                        ><template #item.source_route="{ item }">{{
                            item.source_route || 'Unknown'
                        }}</template></AdminDataTable
                    ></v-card
                >
                <v-card class="analytics-panel table-panel"
                    ><AdminDataTable
                        title="Daily data"
                        :headers="dailyHeaders"
                        :items="report.daily"
                        export-name="otso-promo-daily"
                        :loading="loading"
                        ><template #item.ctr="{ item }">{{
                            percent(item.ctr)
                        }}</template></AdminDataTable
                    ></v-card
                >
                <v-card class="analytics-panel table-panel"
                    ><AdminDataTable
                        title="Retention cohorts"
                        :headers="retentionHeaders"
                        :items="report.retention"
                        export-name="otso-promo-retention"
                        :loading="loading"
                        ><template #item.day="{ item }">D{{ item.day }}</template
                        ><template #item.portal_rate="{ item }">{{
                            percent(item.portal_rate)
                        }}</template
                        ><template #item.otso_rate="{ item }">{{
                            percent(item.otso_rate)
                        }}</template></AdminDataTable
                    ></v-card
                >
                <v-card class="analytics-panel table-panel"
                    ><AdminDataTable
                        title="Navigation and betting friction"
                        :headers="diagnosticHeaders"
                        :items="report.diagnostics"
                        export-name="otso-promo-friction"
                        :loading="loading"
                        no-data-text="No navigation failures, game-list failures, bet-request failures, or token blocks were recorded for this selection."
                        ><template #item.event_name="{ item }">{{
                            label(item.event_name)
                        }}</template
                        ><template #item.reason="{ item }">{{ label(item.reason) }}</template
                        ><template #item.source="{ item }">{{ sourceLabel(item.source) }}</template
                        ><template #item.http_status="{ item }">{{
                            item.http_status ?? '—'
                        }}</template></AdminDataTable
                    ></v-card
                >

                <v-card class="analytics-panel">
                    <div class="panel-heading">
                        <div>
                            <h2>Data quality &amp; definitions</h2>
                            <p>Last refreshed {{ displayOtsoDate(report.generated_at) }}</p>
                        </div>
                    </div>
                    <div class="quality-grid">
                        <div>
                            <strong>{{ count(report.quality.events) }}</strong
                            ><span>Recorded events in selection</span>
                        </div>
                        <div>
                            <strong>{{
                                report.quality.average_delay_seconds === null
                                    ? '—'
                                    : `${report.quality.average_delay_seconds}s`
                            }}</strong
                            ><span>Average delivery delay</span>
                        </div>
                        <div>
                            <strong>{{ count(report.quality.delayed_events) }}</strong
                            ><span>Events delayed over 60 seconds</span>
                        </div>
                        <div>
                            <strong>{{ count(report.quality.clock_skew_events) }}</strong
                            ><span>Events ahead of server clock</span>
                        </div>
                    </div>
                    <p class="analytics-note mt-4">
                        Latest event:
                        {{
                            displayOtsoDate(
                                report.quality.last_occurred_at
                                    ? `${report.quality.last_occurred_at.replace(' ', 'T')}Z`
                                    : null,
                            )
                        }}
                        · Last received:
                        {{
                            displayOtsoDate(
                                report.quality.last_received_at
                                    ? `${report.quality.last_received_at.replace(' ', 'T')}Z`
                                    : null,
                            )
                        }}
                    </p>
                    <ul class="definition-list">
                        <li>
                            CTR = impressions with a Play click ÷ shown impressions. Resuming a
                            dialog does not create another impression.
                        </li>
                        <li>
                            Conversion = players with an attributed bet ÷ exposed players. Each
                            actual bet is attributed once, to the last Play click in the preceding
                            24 hours, or the last impression when no click exists.
                        </li>
                        <li>
                            Gross bet amount includes subsequently refunded bets. It is not revenue.
                            Client request-success events are diagnostics, rather than proof of a
                            recorded bet.
                        </li>
                        <li>
                            Browser tracking is best effort. Missing events and late delivery can
                            change results. These reports show associations; they do not measure the
                            promotion's causal impact.
                        </li>
                    </ul>
                </v-card>
            </template>
        </div>
    </OtsoShell>
</template>

<style scoped>
.promo-analytics {
    font-size: 13px;
}
.analytics-intro,
.intro-actions,
.panel-heading,
.chart-legend,
.step-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.analytics-intro {
    margin-bottom: 24px;
    align-items: flex-start;
}
.intro-copy {
    font-size: 14px;
    margin-bottom: 6px;
}
.intro-actions {
    flex-shrink: 0;
    gap: 8px;
}
.analytics-note,
.metric-card > span:last-child,
.panel-heading p {
    color: #667085;
    font-size: 12px;
    line-height: 1.7;
}
.analytics-panel {
    padding: 24px;
    margin-bottom: 20px;
    border: 1px solid #e8edf2;
}
.range-presets {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.filter-label {
    font-weight: 600;
    margin-right: 8px;
}
.filter-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(110px, 1fr)) minmax(150px, 1.3fr) auto;
    gap: 12px;
    align-items: start;
}
.filter-grid > :last-child {
    margin-top: 2px;
}
.metric-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.metric-card {
    padding: 22px 24px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    border: 1px solid #e8edf2;
}
.metric-label {
    font-size: 12px;
    font-weight: 500;
    color: #475467;
}
.metric-card > strong {
    font-size: clamp(25px, 2.2vw, 34px);
    font-weight: 600;
    letter-spacing: -1px;
    line-height: 1.2;
}
.accent-card {
    border-top: 3px solid rgb(var(--v-theme-primary));
}
.panel-heading {
    margin-bottom: 24px;
    align-items: flex-start;
}
h2 {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 5px;
}
h3 {
    font-size: 14px;
    font-weight: 600;
}
.chart-legend {
    justify-content: flex-end;
    flex-shrink: 0;
    font-size: 11px;
    color: #667085;
}
.chart-legend span {
    display: flex;
    align-items: center;
    gap: 5px;
}
.chart-legend i {
    height: 8px;
    width: 8px;
    border-radius: 2px;
}
.legend-impressions,
.impression-bar {
    background: #b9e8fc;
}
.legend-clicks,
.click-bar {
    background: #008cc4;
}
.trend-scroll {
    overflow-x: auto;
}
.trend-chart {
    display: flex;
    gap: 6px;
    height: 210px;
}
.trend-day {
    flex: 1;
    min-width: 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.trend-bars {
    width: 100%;
    flex: 1;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    gap: 2px;
    border-bottom: 1px solid #eaecf0;
    background: repeating-linear-gradient(
        to top,
        transparent 0,
        transparent calc(25% - 1px),
        #f2f4f7 calc(25% - 1px),
        #f2f4f7 25%
    );
}
.trend-bars > div {
    flex: 1;
    max-width: 16px;
    border-radius: 3px 3px 0 0;
}
.trend-day > span {
    height: 28px;
    padding-top: 8px;
    font-size: 10px;
    white-space: nowrap;
    color: #667085;
}
.analysis-columns,
.tutorial-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}
.journey-row {
    display: flex;
    gap: 14px;
    align-items: center;
    margin-bottom: 22px;
}
.journey-detail {
    flex: 1;
}
.journey-detail > div {
    display: flex;
    justify-content: space-between;
    margin-bottom: 9px;
}
.step-row + .step-row {
    border-top: 1px solid #eaecf0;
    margin-top: 20px;
    padding-top: 20px;
}
.step-title {
    gap: 8px;
    margin-bottom: 12px;
    font-size: 11px;
    color: #475467;
    flex-wrap: wrap;
}
.step-metrics,
.retention-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}
.step-metrics {
    margin-bottom: 14px;
}
.step-metrics > div,
.retention-rates > div,
.quality-grid > div {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.step-metrics strong {
    font-size: 20px;
    font-weight: 600;
}
.step-metrics span,
.retention-rates span,
.quality-grid span {
    font-size: 11px;
    color: #667085;
}
.tutorial-card,
.retention-card {
    border: 1px solid #eaecf0;
    border-radius: 8px;
    padding: 20px;
}
.slide-row {
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr) 30px 42px;
    gap: 12px;
    align-items: center;
    margin-top: 16px;
    font-size: 11px;
}
.slide-row > :nth-last-child(-n + 2) {
    text-align: right;
}
.retention-rates {
    display: flex;
    gap: 26px;
    margin: 18px 0;
    flex-wrap: wrap;
}
.retention-rates strong {
    font-size: 24px;
    font-weight: 600;
}
.quality-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px;
}
.quality-grid strong {
    font-size: 22px;
    font-weight: 600;
}
.definition-list {
    padding-left: 18px;
    margin-top: 16px;
    color: #667085;
    font-size: 12px;
    line-height: 1.9;
}
.empty-panel {
    color: #667085;
    padding: 24px 0;
}
.table-panel {
    padding: 8px 16px;
}
@media (max-width: 1200px) {
    .filter-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
@media (max-width: 960px) {
    .analytics-intro {
        flex-direction: column;
    }
    .analysis-columns,
    .tutorial-grid {
        grid-template-columns: 1fr;
    }
    .metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .analytics-panel {
        padding: 20px;
    }
    .table-panel {
        padding: 8px;
    }
}
@media (max-width: 600px) {
    .filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .filter-grid > :nth-last-child(2) {
        grid-column: 1 / -1;
    }
    .metric-grid {
        gap: 10px;
    }
    .metric-card {
        padding: 16px;
    }
    .metric-card > strong {
        font-size: 25px;
    }
    .panel-heading {
        flex-direction: column;
        gap: 10px;
    }
    .retention-grid {
        grid-template-columns: 1fr;
    }
    .quality-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .step-metrics {
        gap: 8px;
    }
    .step-metrics strong {
        font-size: 18px;
    }
    .analytics-panel {
        padding: 16px;
    }
    .table-panel {
        padding: 4px;
    }
}
</style>
