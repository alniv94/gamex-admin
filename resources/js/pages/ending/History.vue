<script setup lang="ts">
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { useTableFilters } from '@/composables/useTableFilters';
import { exportTableRows } from '@/composables/useTableExport';
import { endingActivity } from '@/components/ending/endingActivity';
import { displayDate } from '@/types/ending';
import EndingLogs from '../../components/ending/EndingLogs.vue';
import type { EndingLog, PageResult } from '../../types/ending';
const props = defineProps<{ logs: PageResult<EndingLog>; filters: Record<string, string> }>();
const filters = reactive({
    action: props.filters.action || '',
    game_id: props.filters.game_id || '',
});
const filterOpen = ref(false);
const loading = ref(false);
const draft = reactive({ ...filters });
watch(
    () => props.filters,
    (value) => Object.assign(filters, { action: '', game_id: '', ...value }),
);
function visit() {
    router.get(
        EndingGameController.history.url(),
        { ...filters },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
            onSuccess: () => (filterOpen.value = false),
        },
    );
}
function openFilters() {
    Object.assign(draft, filters);
    filterOpen.value = true;
}
function applyFilters() {
    Object.assign(filters, draft);
    visit();
}
const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    [{ key: 'action', label: 'Action' }, 'game_id'],
    (changes) => {
        Object.assign(filters, changes);
        visit();
    },
);
function exportRows() {
    exportTableRows('ending-history.csv', [
        ['Time (Philippines)', 'Administrator', 'Action', 'Record', 'Details'],
        ...props.logs.data.map((log) => {
            const entry = endingActivity(log);
            return [
                displayDate(log.created_at),
                log.actor_name,
                log.action,
                entry.recordName,
                entry.summary,
            ];
        }),
    ]);
}
</script>
<template>
    <div>
        <Head title="Historical Ending Logs" />
        <div class="pa-4 pa-md-6">
            <v-btn
                variant="text"
                prepend-icon="mdi-arrow-left"
                class="mb-3"
                @click="router.visit(EndingGameController.index())"
                >Scheduled Games</v-btn
            >
            <h1 class="text-h5 font-weight-bold mb-3">Historical Ending Logs</h1>
            <p class="text-body-2 text-medium-emphasis mb-4">
                Original administrator actions are preserved. Some logs have no game link because
                the referenced legacy record no longer exists.
            </p>
            <v-card>
                <AdminTableToolbar
                    :filters-open="filterOpen"
                    :total="logs.total"
                    :searchable="false"
                    :loading="loading"
                    filterable
                    :active-filters="activeFilters"
                    :exports="[{ title: 'Current page (CSV)', action: exportRows }]"
                    @filter="openFilters"
                    @refresh="visit"
                    @remove-filter="removeFilter"
                    @reset-filters="resetTableFilters"
                />
                <EndingLogs :logs="logs" :toolbar="false" />
            </v-card>
        </div>
        <AdminFilterDialog
            v-model="filterOpen"
            :loading="loading"
            @apply="applyFilters"
            @reset="resetTableFilters"
        >
            <v-text-field v-model="draft.action" label="Action (for example, approve_winner)" />
            <v-text-field v-model="draft.game_id" type="number" min="1" label="Game ID" />
        </AdminFilterDialog>
    </div>
</template>
