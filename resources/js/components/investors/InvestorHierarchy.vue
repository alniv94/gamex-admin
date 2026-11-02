<script setup lang="ts">
import { hierarchy, show } from '@/actions/App/Http/Controllers/InvestorController';
import type { InvestorRow } from '@/types/investors';
import { investorMoney, investorName } from '@/types/investors';
import { Link, useHttp } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{ investor: InvestorRow; kind: 'agents' | 'regular-players' }>();
const emit = defineEmits<{ close: [] }>();
const http = useHttp<
    Record<string, never>,
    {
        records: (InvestorRow & {
            name?: string;
            player_id?: number | null;
            legacy_player_id?: number | null;
        })[];
    }
>({});
const loadError = ref('');
const agents = computed(() => props.kind === 'agents');
const rows = computed(() =>
    (http.response?.records ?? []).map((row) => ({
        ...row,
        name:
            row.name ||
            (row.first_name ? investorName(row) : 'Legacy player #' + row.legacy_player_id),
    })),
);
const headers = computed(() => [
    { title: 'Name', key: 'name' },
    { title: 'Mobile number', key: 'mobile_number' },
    { title: 'Commission', key: 'commission' },
    ...(agents.value
        ? [
              { title: 'Sub commission', key: 'sub_commission' },
              { title: 'Wallet', key: 'wallet' },
          ]
        : []),
    { title: 'Status', key: 'status' },
]);
async function load() {
    loadError.value = '';
    try {
        await http.get(hierarchy.url({ investor: props.investor.id, kind: props.kind }));
    } catch {
        loadError.value = 'The accounts could not be loaded. Please try again.';
    }
}
onMounted(load);
onBeforeUnmount(() => http.cancel());
</script>

<template>
    <v-card>
        <v-card-title class="d-flex align-center ga-3">
            <span
                >{{ agents ? 'Sales Managers' : 'Regular Players' }} —
                {{ investorName(investor) }}</span
            >
            <v-spacer /><v-btn
                icon="mdi-close"
                variant="text"
                aria-label="Close agent list"
                @click="emit('close')"
            />
        </v-card-title>
        <v-card-text>
            <v-alert v-if="loadError" type="error" variant="tonal" class="mb-4"
                >{{ loadError }}<v-btn variant="text" @click="load">Retry</v-btn></v-alert
            >
            <v-data-table
                :headers="headers"
                :items="rows"
                :loading="http.processing"
                :items-per-page="5"
                class="players-table"
            >
                <template #item.name="{ item }"
                    ><Link v-if="agents" :href="show(item.id)">{{ item.name }}</Link
                    ><span v-else>{{ item.name }}</span></template
                >
                <template #item.mobile_number="{ item }">{{
                    item.mobile_number ? '+63' + item.mobile_number.replace(/^\+63/, '') : '—'
                }}</template>
                <template #item.commission="{ item }"
                    >{{ Number(item.commission ?? 0).toFixed(2) }}%</template
                >
                <template #item.sub_commission="{ item }"
                    >{{ Number(item.sub_commission ?? 0).toFixed(2) }}%</template
                >
                <template #item.wallet="{ item }">{{ investorMoney(item.wallet) }}</template>
            </v-data-table>
        </v-card-text>
    </v-card>
</template>
