<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import type { AdminPageProps } from '../../types';
import type { EndingGame, League, Team, Preset } from '../../types/ending';
import { inputDate, money, betType } from '../../types/ending';
import EntityLogo from './EntityLogo.vue';

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
const scheduleDate = ref('');
const scheduleTime = ref('');
watch([scheduleDate, scheduleTime], ([date, time]) => {
    form.schedule = date && time ? `${date}T${time}` : '';
});
const groups = computed(() =>
    [1, 2].map((type) => ({
        type,
        presets: form.presets.filter((p) => p.bet_type_id === type),
        total: form.presets
            .filter((p) => p.bet_type_id === type)
            .reduce((total, p) => total + (Number(p.quantity) || 0), 0),
    })),
);
function resetPresets() {
    form.presets = props.presets.map((preset) => ({ ...preset }));
}
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
    [scheduleDate.value, scheduleTime.value] = form.schedule ? form.schedule.split('T') : ['', ''];
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
    <v-dialog v-model="open" max-width="800" scrollable :persistent="busy" class="ending-dialog">
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
                        variant="outlined"
                        density="compact"
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
                                variant="outlined"
                                density="compact"
                                :error-messages="errors.team_a"
                            >
                                <template #item="{ props: itemProps, item }"
                                    ><v-list-item v-bind="itemProps"
                                        ><template #prepend
                                            ><EntityLogo
                                                :src="item.logo"
                                                :name="item.team_name"
                                                aria-hidden="true"
                                                :size="24"
                                                class="mr-2" /></template></v-list-item
                                ></template> </v-autocomplete></v-col
                        ><v-col cols="12" sm="6"
                            ><v-autocomplete
                                v-model="form.team_b"
                                :items="teams.filter((t) => Number(t.id) !== Number(form.team_a))"
                                item-title="team_name"
                                item-value="id"
                                label="Team B"
                                variant="outlined"
                                density="compact"
                                :error-messages="errors.team_b"
                            >
                                <template #item="{ props: itemProps, item }"
                                    ><v-list-item v-bind="itemProps"
                                        ><template #prepend
                                            ><EntityLogo
                                                :src="item.logo"
                                                :name="item.team_name"
                                                aria-hidden="true"
                                                :size="24"
                                                class="mr-2" /></template></v-list-item
                                ></template> </v-autocomplete></v-col
                    ></v-row>
                    <v-row dense class="mt-1 mb-4">
                        <v-col cols="12" sm="6"
                            ><v-text-field
                                v-model="scheduleDate"
                                label="Schedule Date"
                                type="date"
                                :error-messages="errors.schedule"
                        /></v-col>
                        <v-col cols="12" sm="6"
                            ><v-text-field
                                v-model="scheduleTime"
                                label="Schedule Time"
                                type="time"
                                hint="Philippine time (Asia/Manila)"
                                persistent-hint
                        /></v-col>
                    </v-row>
                    <template v-if="!game">
                        <div class="d-flex align-center ga-2">
                            <h3 class="text-subtitle-1">Card Preset</h3>
                            <v-spacer />
                            <v-btn
                                color="primary"
                                variant="text"
                                size="small"
                                prepend-icon="mdi-restore"
                                :disabled="busy"
                                @click="resetPresets"
                                >Reset Defaults</v-btn
                            >
                        </div>
                        <p class="text-caption text-medium-emphasis mb-3">
                            Adjust these quantities for this game only. Use 0 to exclude a bet
                            amount.
                        </p>
                        <v-row dense
                            ><v-col v-for="group in groups" :key="group.type" cols="12" sm="6"
                                ><div class="ending-preset-panel">
                                    <div
                                        class="d-flex align-center justify-space-between px-3 py-2"
                                    >
                                        <span>{{ betType(group.type) }}</span
                                        ><v-chip color="primary" size="x-small" variant="outlined"
                                            >{{ group.total }} cards</v-chip
                                        >
                                    </div>
                                    <v-table density="compact" class="ending-preset-table"
                                        ><thead>
                                            <tr>
                                                <th>Bet amount</th>
                                                <th class="text-right">Card quantity</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="preset in group.presets"
                                                :key="preset.config_id"
                                            >
                                                <td>{{ money(preset.amount) }}</td>
                                                <td>
                                                    <v-text-field
                                                        v-model.number="preset.quantity"
                                                        type="number"
                                                        min="0"
                                                        max="1000"
                                                        step="1"
                                                        hide-details
                                                        :aria-label="`${betType(preset.bet_type_id)} ${money(preset.amount)} card quantity`"
                                                    />
                                                </td>
                                            </tr>
                                            <tr v-if="!group.presets.length">
                                                <td
                                                    colspan="2"
                                                    class="text-center text-medium-emphasis py-4"
                                                >
                                                    No active presets.
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </div></v-col
                            ></v-row
                        >
                        <p v-if="!form.presets.length" class="text-medium-emphasis">
                            No preset cards configured. You can add cards from the game details.
                        </p>
                    </template>
                </v-form> </v-card-text
            ><v-divider />
            <v-card-actions class="ending-dialog-actions"
                ><v-btn
                    color="primary"
                    variant="flat"
                    type="submit"
                    form="ending-game-form"
                    :loading="busy"
                    >{{ game ? 'Save Changes' : 'Create Game' }}</v-btn
                ><v-btn variant="outlined" :disabled="busy" @click="open = false"
                    >Cancel</v-btn
                ></v-card-actions
            >
        </v-card>
    </v-dialog>
</template>
