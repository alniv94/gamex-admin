<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import type { AdminPageProps } from '../../types';
import type { EndingGame, League, Team, Preset } from '../../types/ending';
import { inputDate, money, betType } from '../../types/ending';

const open = defineModel<boolean>({ default: false });
const props = defineProps<{
    leagues: League[];
    teams: Team[];
    presets: Preset[];
    game?: EndingGame;
}>();
const page = usePage<AdminPageProps>();
const form = useForm({
    league_id: null as number | null,
    team_a: null as number | null,
    team_b: null as number | null,
    schedule: '',
    presets: [] as Preset[],
});
const busy = computed(() => form.processing);
const errors = computed(() => form.errors);
const error = computed(() => Object.values(form.errors).flat().join(' '));
const teams = computed(() =>
    props.teams.filter((t) => Number(t.league_id) === Number(form.league_id)),
);
watch(open, (value) => {
    if (!value) return;
    form.defaults({
        league_id: props.game?.league_id || null,
        team_a: props.game?.team_a || null,
        team_b: props.game?.team_b || null,
        schedule: inputDate(props.game?.schedule || null),
        presets: props.presets.map((p) => ({ ...p })),
    });
    form.resetAndClearErrors();
});
watch(
    () => form.league_id,
    () => {
        if (!teams.value.some((t) => Number(t.id) === Number(form.team_a))) form.team_a = null;
        if (!teams.value.some((t) => Number(t.id) === Number(form.team_b))) form.team_b = null;
    },
);
function save() {
    if (form.processing) return;
    form.submit(
        props.game ? EndingGameController.update(props.game.id) : EndingGameController.store(),
        {
            preserveScroll: true,
            headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
            onSuccess: () => {
                open.value = false;
            },
        },
    );
}
</script>
<template>
    <v-dialog v-model="open" max-width="900" scrollable :persistent="busy">
        <v-card>
            <v-card-title>{{ game ? 'Edit Game' : 'Create Game' }}</v-card-title
            ><v-divider />
            <v-card-text>
                <v-alert v-if="error" type="error" variant="tonal" class="mb-4" role="alert"
                    >{{ error }}
                </v-alert>
                <v-form id="ending-game-form" :disabled="busy" @submit.prevent="save">
                    <v-autocomplete
                        v-model="form.league_id"
                        :items="leagues"
                        item-title="league"
                        item-value="id"
                        label="League"
                        :error-messages="errors.league_id"
                    />
                    <v-row
                        ><v-col cols="12" sm="6"
                            ><v-autocomplete
                                v-model="form.team_a"
                                :items="teams"
                                item-title="team_name"
                                item-value="id"
                                label="Team A"
                                :error-messages="errors.team_a" /></v-col
                        ><v-col cols="12" sm="6"
                            ><v-autocomplete
                                v-model="form.team_b"
                                :items="teams.filter((t) => Number(t.id) !== Number(form.team_a))"
                                item-title="team_name"
                                item-value="id"
                                label="Team B"
                                :error-messages="errors.team_b" /></v-col
                    ></v-row>
                    <v-text-field
                        v-model="form.schedule"
                        label="Schedule (Philippine time)"
                        type="datetime-local"
                        :error-messages="errors.schedule"
                    />
                    <template v-if="!game">
                        <h3 class="text-subtitle-1 mb-2">Preset cards</h3>
                        <p class="text-caption mb-3">
                            Prize amounts follow the current configuration. Set a quantity to zero
                            to exclude a preset.
                        </p>
                        <v-row dense
                            ><v-col
                                v-for="preset in form.presets"
                                :key="`${preset.bet_type_id}-${preset.config_id}`"
                                cols="12"
                                sm="6"
                                md="4"
                                ><v-text-field
                                    v-model.number="preset.quantity"
                                    type="number"
                                    min="0"
                                    max="1000"
                                    :label="`${betType(preset.bet_type_id)} · ${money(preset.amount)}`" /></v-col
                        ></v-row>
                        <p v-if="!form.presets.length" class="text-medium-emphasis">
                            No preset cards configured. You can add cards from the game details.
                        </p>
                    </template>
                </v-form> </v-card-text
            ><v-divider />
            <v-card-actions
                ><v-spacer /><v-btn :disabled="busy" @click="open = false">Cancel</v-btn
                ><v-btn
                    color="primary"
                    variant="flat"
                    type="submit"
                    form="ending-game-form"
                    :loading="busy"
                    >{{ game ? 'Save Changes' : 'Create Game' }}</v-btn
                ></v-card-actions
            >
        </v-card>
    </v-dialog>
</template>
