<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import EndingCatalogController from '@/actions/App/Http/Controllers/EndingCatalogController';
import type { AdminPageProps } from '../../types';
import type { League, Team } from '../../types/ending';
import EntityLogo from './EntityLogo.vue';
const open = defineModel<boolean>({ default: false });
const props = defineProps<{
    kind: 'team' | 'league';
    team?: Team | null;
    league?: League | null;
    leagues?: League[];
}>();
const page = usePage<AdminPageProps>();
const form = useForm({
    league_id: null as number | null,
    team_name: '',
    team_alias: '',
    league: '',
    logo: '',
    is_local: 'Local',
    is_active: 'Active',
});
const editing = computed(() => (props.kind === 'team' ? props.team : props.league));
const title = computed(
    () => `${editing.value ? 'Edit' : 'Create'} ${props.kind === 'team' ? 'Team' : 'League'}`,
);
watch(open, (value) => {
    if (!value) return;
    form.defaults({
        league_id: props.team?.league_id || null,
        team_name: props.team?.team_name || '',
        team_alias: props.team?.team_alias || '',
        league: props.league?.league || '',
        logo: editing.value?.logo || '',
        is_local: props.league?.is_local || 'Local',
        is_active: editing.value?.is_active || 'Active',
    });
    form.resetAndClearErrors();
});
function save() {
    if (form.processing) return;
    const target =
        props.kind === 'team'
            ? props.team
                ? EndingCatalogController.updateTeam(props.team.id)
                : EndingCatalogController.storeTeam()
            : props.league
              ? EndingCatalogController.updateLeague(props.league.id)
              : EndingCatalogController.storeLeague();
    form.submit(target, {
        preserveScroll: true,
        headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <v-dialog
        v-model="open"
        max-width="800"
        scrollable
        :persistent="form.processing"
        class="ending-dialog"
    >
        <v-card>
            <v-card-title>{{ title }}</v-card-title
            ><v-divider />
            <v-card-text>
                <v-alert
                    v-if="form.hasErrors"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                    role="alert"
                    >{{ Object.values(form.errors).join(' ') }}</v-alert
                >
                <v-form id="ending-catalog-form" :disabled="form.processing" @submit.prevent="save">
                    <v-autocomplete
                        v-if="kind === 'team'"
                        v-model="form.league_id"
                        :items="leagues"
                        item-title="league"
                        item-value="id"
                        label="League"
                        variant="outlined"
                        density="compact"
                        class="mb-4"
                        :error-messages="form.errors.league_id"
                    />
                    <v-row dense>
                        <v-col cols="12" :sm="kind === 'team' ? 6 : 12">
                            <v-text-field
                                v-if="kind === 'team'"
                                v-model="form.team_name"
                                label="Name"
                                :error-messages="form.errors.team_name"
                            />
                            <v-text-field
                                v-else
                                v-model="form.league"
                                label="Name"
                                :error-messages="form.errors.league"
                            />
                        </v-col>
                        <v-col v-if="kind === 'team'" cols="12" sm="6"
                            ><v-text-field
                                v-model="form.team_alias"
                                label="Alias"
                                :error-messages="form.errors.team_alias"
                        /></v-col>
                    </v-row>
                    <div class="d-flex align-center ga-3 my-4">
                        <v-text-field
                            v-model="form.logo"
                            label="Logo URL"
                            :error-messages="form.errors.logo"
                        />
                        <EntityLogo
                            :src="form.logo"
                            :name="kind === 'team' ? form.team_name : form.league"
                            :size="48"
                        />
                    </div>
                    <v-radio-group
                        v-if="kind === 'league'"
                        v-model="form.is_local"
                        inline
                        :error-messages="form.errors.is_local"
                        ><v-radio label="Local" value="Local" /><v-radio
                            label="International"
                            value="International"
                    /></v-radio-group>
                    <v-switch
                        v-model="form.is_active"
                        true-value="Active"
                        false-value="Inactive"
                        color="success"
                        :label="`Status: ${form.is_active}`"
                        hide-details
                        :error-messages="form.errors.is_active"
                    />
                </v-form>
            </v-card-text>
            <v-card-actions class="pa-4"
                ><v-spacer /><v-btn
                    variant="outlined"
                    :disabled="form.processing"
                    @click="open = false"
                    >Cancel</v-btn
                ><v-btn
                    color="primary"
                    variant="flat"
                    type="submit"
                    form="ending-catalog-form"
                    :loading="form.processing"
                    >{{ editing ? 'Save Changes' : 'Create' }}</v-btn
                ></v-card-actions
            >
        </v-card>
    </v-dialog>
</template>
