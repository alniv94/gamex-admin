<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { DataTableHeader } from 'vuetify';
import AdminDataTable from '@/components/AdminDataTable.vue';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { money } from '@/types/ending';

interface SummaryRecord {
    year: number;
    total_players: number;
    bet_count: number;
    bet_amount: string;
}

defineProps<{ records: SummaryRecord[] }>();

const loading = ref(false);
const sortBy = ref<{ key: string; order: 'asc' | 'desc' }[]>([{ key: 'year', order: 'asc' }]);
const headers: DataTableHeader<SummaryRecord>[] = [
    { title: 'Year', key: 'year', width: '16%' },
    { title: 'Registered Players', key: 'total_players', width: '34%' },
    { title: 'Bet Count', key: 'bet_count', width: '24%' },
    { title: 'Bet Amount', key: 'bet_amount', width: '26%', sort: (a, b) => Number(a) - Number(b) },
];

function refresh() {
    if (loading.value) return;

    router.reload({
        only: ['records'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}
</script>

<template>
    <div class="dashboard-content summary-report-page">
        <Head title="Summary Report" />
        <h1 class="page-heading">Summary Report</h1>
        <v-card class="shadow">
            <AdminTableToolbar
                :searchable="false"
                :loading="loading"
                refresh-label="Refresh summary report"
                @refresh="refresh"
            />
            <AdminDataTable
                v-model:sort-by="sortBy"
                :headers="headers"
                :items="records"
                :toolbar="false"
                :loading="loading"
                :items-per-page-options="[10, 25, 50, 100]"
                item-value="year"
                aria-label="Yearly summary report"
                loading-text="Loading, please wait a moment"
                no-data-text="No summary report records available."
            >
                <template #item.bet_amount="{ item }">₱{{ money(item.bet_amount) }}</template>
            </AdminDataTable>
        </v-card>
    </div>
</template>

<style scoped>
.summary-report-page :deep(.v-table__wrapper > table) {
    min-width: 640px;
}

.summary-report-page :deep(.admin-table .v-table__wrapper > table > thead > tr > th) {
    background: #fff;
    text-transform: uppercase;
    font-weight: 400;
}
</style>
