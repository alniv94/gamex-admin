<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { updateSettings } from '@/actions/App/Http/Controllers/LuckyFourController';
import LuckyFourShell from '@/components/lucky-four/LuckyFourShell.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import { displayDate, money } from '@/types/ending';
import type { PageResult } from '@/types/ending';
import { prizeFields } from '@/types/lucky-four';
import type { LuckyFourPrizes } from '@/types/lucky-four';
import type { AdminPageProps } from '@/types';
const props = defineProps<{
    settings: LuckyFourPrizes | null;
    logs: PageResult<LuckyFourPrizes>;
    migrationReady: boolean;
}>();
const page = usePage<AdminPageProps>();
const form = useForm({
    jackpot_prize: props.settings?.jackpot_prize ?? '',
    three_digits_prize: props.settings?.three_digits_prize ?? '',
    initial_amount: props.settings?.initial_amount ?? '',
    percentage_deduction: props.settings?.percentage_deduction ?? '',
    bet_amount: props.settings?.bet_amount ?? '',
});
function save() {
    form.submit(updateSettings(), {
        preserveScroll: true,
        headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
    });
}
</script>
<template>
    <LuckyFourShell title="Prize Settings" :migration-ready="migrationReady">
        <v-card class="mb-5"
            ><v-card-text>
                <p class="mb-5">
                    These settings apply when Lucky Four is enabled for a game. Each game keeps its
                    own prize amounts. The jackpot starts at the configured jackpot prize; the pool
                    contribution adds a percentage of eligible wagers.
                </p>
                <form @submit.prevent="save">
                    <v-row>
                        <v-col v-for="field in prizeFields" :key="field.key" cols="12" sm="6" md="4"
                            ><v-text-field
                                v-model="form[field.key]"
                                :label="field.label"
                                type="number"
                                min="0"
                                step="0.01"
                                :max="field.key === 'percentage_deduction' ? 100 : 99999999.99"
                                :error-messages="form.errors[field.key]"
                        /></v-col> </v-row
                    ><v-btn
                        type="submit"
                        color="primary"
                        :loading="form.processing"
                        :disabled="!migrationReady"
                        >Save settings</v-btn
                    >
                </form>
            </v-card-text></v-card
        >
        <v-card
            ><v-card-title>Settings History</v-card-title
            ><v-table class="ending-table"
                ><thead>
                    <tr>
                        <th>Changed at</th>
                        <th v-for="field in prizeFields" :key="field.key">{{ field.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="log in logs.data" :key="log.id">
                        <td>{{ displayDate(log.created_at ?? null) }}</td>
                        <td v-for="field in prizeFields" :key="field.key">
                            {{ money(log[field.key]) }}
                        </td>
                    </tr>
                    <tr v-if="!logs.data.length">
                        <td colspan="6" class="text-center pa-5">No settings changes recorded.</td>
                    </tr>
                </tbody></v-table
            ><EndingPager :page="logs"
        /></v-card>
    </LuckyFourShell>
</template>
