<script setup lang="ts">
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import EndingLogs from '../../components/ending/EndingLogs.vue';
import type { EndingLog, PageResult } from '../../types/ending';
const props = defineProps<{ logs: PageResult<EndingLog>; filters: Record<string, string> }>();
const filters = reactive({
    action: props.filters.action || '',
    game_id: props.filters.game_id || '',
});
</script>
<template>
    <Head title="Historical Ending Logs" /><AdminLayout
        ><div class="pa-4 pa-md-6">
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
            <v-card
                ><v-form
                    class="pa-4"
                    @submit.prevent="
                        router.get(
                            EndingGameController.history.url(),
                            { ...filters },
                            { preserveState: true },
                        )
                    "
                    ><v-row dense
                        ><v-col cols="12" sm="5"
                            ><v-text-field
                                v-model="filters.action"
                                label="Action (for example, approve_winner)"
                                hide-details /></v-col
                        ><v-col cols="12" sm="4"
                            ><v-text-field
                                v-model="filters.game_id"
                                type="number"
                                label="Game ID"
                                hide-details /></v-col
                        ><v-col cols="12" sm="3" class="d-flex align-center"
                            ><v-btn type="submit" color="primary" variant="outlined"
                                >Filter</v-btn
                            ></v-col
                        ></v-row
                    ></v-form
                ><v-divider /><EndingLogs :logs="logs"
            /></v-card></div
    ></AdminLayout>
</template>
