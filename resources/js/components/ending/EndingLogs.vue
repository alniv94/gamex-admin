<script setup lang="ts">
import type { EndingLog, PageResult } from '../../types/ending';
import { displayDate } from '../../types/ending';
import EndingPager from './EndingPager.vue';
defineProps<{ logs: PageResult<EndingLog> }>();
function details(row: EndingLog) {
    if (row.details) return row.details;
    return JSON.stringify({ before: row.before, after: row.after }, null, 2);
}
</script>
<template>
    <v-table density="compact">
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
            <tr v-for="log in logs.data" :key="log.id">
                <td class="text-no-wrap">{{ displayDate(log.created_at) }}</td>
                <td>
                    {{
                        log.actor_name || (log.user_id ? `Legacy admin #${log.user_id}` : 'System')
                    }}
                </td>
                <td>{{ log.action.replaceAll('_', ' ') }}</td>
                <td>{{ log.subject_type }} #{{ log.subject_id || log.reference_id }}</td>
                <td>
                    <details>
                        <summary>{{ log.reason || 'View details' }}</summary>
                        <pre class="ending-log-details">{{ details(log) }}</pre>
                    </details>
                </td>
            </tr>
            <tr v-if="!logs.data.length">
                <td colspan="5" class="pa-6 text-center text-medium-emphasis">
                    No activity recorded.
                </td>
            </tr>
        </tbody>
    </v-table>
    <EndingPager :page="logs" />
</template>
<style scoped>
.ending-log-details {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    min-width: 180px;
    max-width: 480px;
    padding: 8px 0;
    font-size: 12px;
}
summary {
    cursor: pointer;
}
</style>
