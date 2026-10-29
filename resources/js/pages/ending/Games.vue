<script setup lang="ts">
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import { Head, router, usePage, Link } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import GameForm from '../../components/ending/GameForm.vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import type { AdminPageProps } from '../../types';
import type { EndingGame, League, Team, Preset, PageResult } from '../../types/ending';
import { displayDate } from '../../types/ending';
const props = defineProps<{
    games: PageResult<EndingGame>;
    leagues: League[];
    teams: Team[];
    presets: Preset[];
    filters: Record<string, string>;
    migrationReady: boolean;
}>();
const page = usePage<AdminPageProps>();
const canCreate = page.props.auth.user?.permissions.some((p) => p.id === 32);
const form = reactive({
    search: props.filters.search || '',
    league_id: props.filters.league_id || null,
    status: props.filters.status || null,
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
});
const create = ref(false);
function filter() {
    router.get(
        EndingGameController.index.url(),
        { ...form },
        { preserveState: true, replace: true },
    );
}
function reset() {
    form.search = '';
    form.league_id = null;
    form.status = null;
    form.date_from = '';
    form.date_to = '';
    filter();
}
</script>
<template>
    <Head title="Scheduled Games" />
    <AdminLayout
        ><div class="pa-4 pa-md-6">
            <div class="text-caption text-medium-emphasis">Ending Game</div>
            <div class="d-flex align-center flex-wrap ga-3 mb-5">
                <h1 class="text-h5 font-weight-bold">Scheduled Games</h1>
                <v-spacer /><v-btn
                    variant="text"
                    :href="EndingGameController.history.url()"
                    @click.prevent="router.visit(EndingGameController.history())"
                    >Historical Logs</v-btn
                ><v-btn
                    v-if="canCreate"
                    color="primary"
                    :disabled="!migrationReady"
                    prepend-icon="mdi-plus"
                    @click="create = true"
                    >Create Game</v-btn
                >
            </div>
            <v-alert v-if="!migrationReady" type="info" variant="tonal" class="mb-4"
                >Historical ending games are being prepared. Game creation becomes available after
                the import is verified.</v-alert
            >
            <v-card>
                <v-form class="pa-4" @submit.prevent="filter"
                    ><v-row dense>
                        <v-col cols="12" md="4"
                            ><v-text-field
                                v-model="form.search"
                                label="Search teams or game ID"
                                prepend-inner-icon="mdi-magnify"
                                hide-details
                        /></v-col>
                        <v-col cols="12" sm="6" md="4"
                            ><v-select
                                v-model="form.league_id"
                                :items="leagues"
                                item-title="league"
                                item-value="id"
                                label="League"
                                clearable
                                hide-details
                        /></v-col>
                        <v-col cols="12" sm="6" md="4"
                            ><v-select
                                v-model="form.status"
                                :items="[
                                    { title: 'Scheduled', value: 'scheduled' },
                                    { title: 'Ongoing', value: 'ongoing' },
                                    { title: 'Finished', value: 'finished' },
                                ]"
                                label="Status"
                                clearable
                                hide-details
                        /></v-col>
                        <v-col cols="12" sm="6" md="4"
                            ><v-text-field
                                v-model="form.date_from"
                                label="From (Philippines)"
                                type="date"
                                hide-details /></v-col
                        ><v-col cols="12" sm="6" md="4"
                            ><v-text-field
                                v-model="form.date_to"
                                label="To (Philippines)"
                                type="date"
                                hide-details
                        /></v-col>
                        <v-col cols="12" md="4" class="d-flex align-center ga-2"
                            ><v-btn type="submit" color="primary" variant="outlined"
                                >Apply Filters</v-btn
                            ><v-btn variant="text" @click="reset">Reset</v-btn></v-col
                        >
                    </v-row></v-form
                ><v-divider />
                <v-table
                    ><thead>
                        <tr>
                            <th>ID</th>
                            <th>League / Game</th>
                            <th>Schedule (Philippines)</th>
                            <th>Betting</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="game in games.data" :key="game.id">
                            <td>#{{ game.id }}</td>
                            <td>
                                <div class="text-caption text-medium-emphasis">
                                    {{ game.league || 'Legacy league unavailable' }}
                                </div>
                                <Link
                                    :href="EndingGameController.show.url(game.id)"
                                    class="text-primary font-weight-medium"
                                    >{{ game.team_a_name || `Team #${game.team_a}` }} vs
                                    {{ game.team_b_name || `Team #${game.team_b}` }}</Link
                                >
                            </td>
                            <td class="text-no-wrap">{{ displayDate(game.schedule) }}</td>
                            <td>
                                <v-chip
                                    size="small"
                                    :color="game.betting === 'Enabled' ? 'success' : 'default'"
                                    >{{ game.betting }}</v-chip
                                >
                            </td>
                            <td class="text-no-wrap">{{ game.status }}</td>
                            <td>
                                <v-btn
                                    variant="text"
                                    size="small"
                                    :href="EndingGameController.show.url(game.id)"
                                    @click.prevent="
                                        router.visit(EndingGameController.show(game.id))
                                    "
                                    >Details</v-btn
                                >
                            </td>
                        </tr>
                        <tr v-if="!games.data.length">
                            <td colspan="6" class="text-center pa-8 text-medium-emphasis">
                                No games found.
                            </td>
                        </tr>
                    </tbody></v-table
                ><EndingPager :page="games" />
            </v-card>
            <GameForm v-model="create" :leagues="leagues" :teams="teams" :presets="presets" /></div
    ></AdminLayout>
</template>
