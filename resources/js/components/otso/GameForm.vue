<script setup lang="ts">
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { store, update } from '@/actions/App/Http/Controllers/OtsoPanaloController';
import { displayDate, inputDate, money } from '@/types/ending';
import { prizeFields } from '@/types/otso';
import type { OtsoGame, OtsoConfig, AvailableGame } from '@/types/otso';
const props = defineProps<{
    game?: OtsoGame;
    availableGames: AvailableGame[];
    activeConfig: OtsoConfig | null;
}>();
const emit = defineEmits<{ saved: []; close: [] }>();
const page = usePage();
const form = useForm({
    ending_game_id: props.game?.ending_game_id ?? (null as number | null),
    betting_deadline: inputDate(props.game?.betting_deadline ?? null),
});
const choices = computed(() => {
    const rows = props.availableGames.map((game) => ({
        title:
            '#' +
            game.id +
            ' · ' +
            game.team_a_name +
            ' vs ' +
            game.team_b_name +
            ' · ' +
            displayDate(game.schedule),
        value: game.id,
    }));
    if (props.game && !rows.some((row) => row.value === props.game?.ending_game_id)) {
        rows.unshift({
            title: props.game.team_a_name + ' vs ' + props.game.team_b_name,
            value: props.game.ending_game_id,
        });
    }
    return rows;
});
const selected = computed(() =>
    props.availableGames.find((game) => game.id === form.ending_game_id),
);
function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('saved') };
    if (props.game) form.put(update.url(props.game.id), options);
    else form.post(store.url(), options);
}
</script>
<template>
    <v-card class="ending-dialog">
        <v-card-title>{{ game ? 'Edit Game' : 'Create Otso Panalo Game' }}</v-card-title>
        <v-divider />
        <v-form @submit.prevent="submit">
            <v-card-text>
                <v-alert v-if="!activeConfig" type="warning" variant="tonal" class="mb-4"
                    >Activate a game configuration before creating or editing a game.</v-alert
                >
                <v-alert v-if="page.props.errors.action" type="error" class="mb-4">{{
                    page.props.errors.action
                }}</v-alert>
                <v-autocomplete
                    v-model="form.ending_game_id"
                    :items="choices"
                    label="Available Ending Game"
                    :error-messages="form.errors.ending_game_id"
                    :disabled="form.processing"
                />
                <v-text-field
                    v-if="game"
                    v-model="form.betting_deadline"
                    type="datetime-local"
                    label="Betting Deadline (Manila)"
                    :error-messages="form.errors.betting_deadline"
                />
                <p v-else-if="selected" class="text-caption mb-4">
                    Betting closes 30 minutes before {{ displayDate(selected.schedule) }}.
                </p>
                <v-row v-if="activeConfig">
                    <v-col v-for="field in prizeFields" :key="field.key" cols="12" sm="6">
                        <v-text-field
                            :model-value="
                                money(
                                    field.key === 'jackpot_prize' && game
                                        ? game.jackpot_prize
                                        : activeConfig[field.key],
                                ) + (field.key === 'pool_percentage' ? '%' : '')
                            "
                            :label="field.label"
                            readonly
                            hide-details
                        />
                    </v-col>
                </v-row>
                <p class="text-caption mt-4">
                    New games start with betting disabled. The jackpot comes from the active game
                    configuration and does not roll over.
                </p>
            </v-card-text>
            <v-card-actions class="ending-dialog-actions">
                <v-btn variant="text" :disabled="form.processing" @click="emit('close')"
                    >Cancel</v-btn
                >
                <v-btn
                    color="primary"
                    type="submit"
                    :loading="form.processing"
                    :disabled="!activeConfig || !form.ending_game_id"
                    >{{ game ? 'Save Changes' : 'Create Game' }}</v-btn
                >
            </v-card-actions>
        </v-form>
    </v-card>
</template>
