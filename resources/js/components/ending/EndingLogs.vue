<script setup lang="ts">
import { computed, ref } from 'vue';
import type { EndingLog, PageResult } from '../../types/ending';
import { displayDate } from '../../types/ending';
import EndingPager from './EndingPager.vue';
import { endingActivity } from './endingActivity';

const props = defineProps<{ logs: PageResult<EndingLog> }>();
const entries = computed(() => props.logs.data.map(endingActivity));
const expanded = ref(new Set<number>());
function toggle(id: number) {
    if (expanded.value.has(id)) expanded.value.delete(id);
    else expanded.value.add(id);
}
</script>
<template>
    <div class="ending-activity">
        <v-table density="compact" class="ending-activity-table">
            <thead>
                <tr>
                    <th>Time (Philippines)</th>
                    <th>Administrator</th>
                    <th>Action</th>
                    <th>Record</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="entry in entries" :key="entry.log.id">
                    <tr>
                        <td class="text-no-wrap">{{ displayDate(entry.log.created_at) }}</td>
                        <td>
                            {{
                                entry.log.actor_name ||
                                (entry.log.user_id
                                    ? 'Administrator #' + entry.log.user_id
                                    : 'System')
                            }}
                        </td>
                        <td class="text-no-wrap">{{ entry.log.action.replaceAll('_', ' ') }}</td>
                        <td class="text-no-wrap">{{ entry.recordName }}</td>
                        <td class="ending-activity-summary">
                            <div>{{ entry.summary }}</div>
                            <div v-if="entry.context" class="ending-activity-context">
                                {{ entry.context }}
                            </div>
                            <v-btn
                                variant="text"
                                color="primary"
                                size="small"
                                :prepend-icon="
                                    expanded.has(entry.log.id)
                                        ? 'mdi-chevron-up'
                                        : 'mdi-chevron-down'
                                "
                                :aria-expanded="expanded.has(entry.log.id)"
                                :aria-controls="'ending-log-' + entry.log.id"
                                @click="toggle(entry.log.id)"
                                >{{
                                    expanded.has(entry.log.id) ? 'Hide details' : 'View details'
                                }}</v-btn
                            >
                        </td>
                    </tr>
                    <tr v-if="expanded.has(entry.log.id)" class="ending-activity-expanded">
                        <td colspan="5">
                            <div :id="'ending-log-' + entry.log.id" class="ending-activity-detail">
                                <div class="ending-activity-detail-heading">
                                    <v-icon icon="mdi-history" size="18" /><span
                                        >Recorded changes</span
                                    ><span class="ending-activity-context">{{
                                        entry.recordName
                                    }}</span>
                                </div>
                                <p v-if="entry.note" class="ending-activity-note">
                                    {{ entry.note }}
                                </p>
                                <p v-if="entry.narrative" class="ending-activity-narrative">
                                    {{ entry.narrative }}
                                </p>
                                <p v-if="entry.log.reason" class="ending-activity-narrative">
                                    <strong>Reason:</strong> {{ entry.log.reason }}
                                </p>
                                <v-table
                                    v-if="entry.changes.length"
                                    density="compact"
                                    class="ending-activity-changes"
                                    aria-label="Before and after values"
                                >
                                    <thead>
                                        <tr>
                                            <th>Field</th>
                                            <th>Before</th>
                                            <th>After</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="change in entry.changes" :key="change.field">
                                            <td>{{ change.label }}</td>
                                            <td class="ending-activity-before">
                                                {{ change.before }}
                                            </td>
                                            <td class="ending-activity-after">
                                                {{ change.after }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                                <section
                                    v-if="entry.presets.length"
                                    class="ending-activity-presets"
                                    aria-label="Presets at game creation"
                                >
                                    <h3>Presets at game creation</h3>
                                    <p>
                                        These are the quantities and prizes saved when this game was
                                        created.
                                    </p>
                                    <v-table density="compact" aria-label="Initial card presets">
                                        <thead>
                                            <tr>
                                                <th>Bet type</th>
                                                <th>Amount</th>
                                                <th>Initial cards</th>
                                                <th>Quarter prizes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="preset in entry.presets" :key="preset.key">
                                                <td>{{ preset.type }}</td>
                                                <td class="text-no-wrap">{{ preset.amount }}</td>
                                                <td>{{ preset.cards }}</td>
                                                <td>
                                                    <div class="ending-activity-prizes">
                                                        <span
                                                            v-for="prize in preset.prizes"
                                                            :key="prize.quarter"
                                                            >{{ prize.quarter }}:
                                                            {{ prize.value }}</span
                                                        ><span
                                                            v-if="!preset.prizes.length"
                                                            class="ending-activity-context"
                                                            >{{
                                                                preset.excluded
                                                                    ? 'Excluded from initial cards'
                                                                    : 'Prize details not recorded'
                                                            }}</span
                                                        >
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </section>
                                <section
                                    v-if="entry.prizeRows.length"
                                    class="ending-activity-presets"
                                    aria-label="Recorded quarter prizes"
                                >
                                    <h3>Recorded quarter prizes</h3>
                                    <div class="ending-activity-prizes">
                                        <span v-for="prize in entry.prizeRows" :key="prize.quarter"
                                            >{{ prize.quarter }}: {{ prize.value }}</span
                                        >
                                    </div>
                                </section>
                                <p
                                    v-if="
                                        !entry.changes.length &&
                                        !entry.narrative &&
                                        !entry.log.reason
                                    "
                                    class="ending-activity-context"
                                >
                                    No additional values were recorded.
                                </p>
                            </div>
                        </td>
                    </tr>
                </template>
                <tr v-if="!entries.length">
                    <td colspan="5" class="pa-6 text-center text-medium-emphasis">
                        No activity recorded.
                    </td>
                </tr>
            </tbody>
        </v-table>
        <EndingPager :page="logs" />
    </div>
</template>
<style scoped>
.ending-activity-table :deep(table) {
    min-width: 760px;
}
.ending-activity-summary {
    min-width: 240px;
    padding-block: 12px !important;
    font-size: 12px;
    line-height: 1.6;
}
.ending-activity-summary .v-btn {
    height: 28px !important;
    padding: 0 4px;
    margin-top: 4px;
    font-size: 10px;
}
.ending-activity-context {
    color: #7c8792;
    font-size: 11px;
    line-height: 1.6;
}
.ending-activity-expanded > td {
    background: #f8fafc;
    padding: 16px !important;
}
.ending-activity-detail {
    max-width: 1100px;
    padding: 16px;
    border: 1px solid #dfe7ee;
    border-radius: 6px;
    background: white;
    overflow-wrap: anywhere;
    font-size: 12px;
}
.ending-activity-detail-heading {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
    color: #38546a;
    font-weight: 500;
}
.ending-activity-note {
    margin: 0 0 14px;
    padding: 10px 12px;
    border-left: 3px solid #a8c9dd;
    background: #f1f7fb;
    color: #597084;
    font-size: 11px;
    line-height: 1.7;
}
.ending-activity-narrative {
    margin: 0 0 14px;
    white-space: pre-wrap;
    line-height: 1.7;
}
.ending-activity-changes :deep(table) {
    min-width: 0;
    table-layout: fixed;
}
.ending-activity-changes :deep(th),
.ending-activity-changes :deep(td) {
    width: 33.333%;
    font-size: 12px;
    white-space: normal;
    overflow-wrap: anywhere;
}
.ending-activity-before {
    color: #75808c;
    background: #fafbfc;
}
.ending-activity-after {
    color: #24583d;
    background: #f3faf6;
    font-weight: 500;
}
.ending-activity-presets {
    margin-top: 20px;
}
.ending-activity-presets h3 {
    margin: 0 0 6px;
    font-size: 13px;
}
.ending-activity-presets > p {
    margin: 0 0 12px;
    color: #7c8792;
    font-size: 11px;
}
.ending-activity-presets :deep(table) {
    min-width: 600px;
}
.ending-activity-prizes {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
    padding-block: 8px;
    font-size: 11px;
}
</style>
