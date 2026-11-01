<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { index, show } from '@/actions/App/Http/Controllers/LuckyFourController';
import LuckyFourShell from '@/components/lucky-four/LuckyFourShell.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import EntityLogo from '@/components/ending/EntityLogo.vue';
import type { EndingGame, PageResult } from '@/types/ending';
import { displayDate } from '@/types/ending';
import type { LuckyFourFilters } from '@/types/lucky-four';
const props = defineProps<{
    games: PageResult<EndingGame>;
    filters: LuckyFourFilters;
    migrationReady: boolean;
}>();
const form = useForm({
    search: props.filters.search || '',
    status: props.filters.status || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
});
function filter() {
    form.get(index.url(), { preserveState: true });
}
</script>
<template>
    <LuckyFourShell title="Games" :migration-ready="migrationReady">
        <p class="mb-4 text-medium-emphasis">
            Enable Lucky Four from an Ending game's details. The schedule and results are shared.
        </p>
        <v-card>
            <form class="pa-4" @submit.prevent="filter">
                <v-row dense>
                    <v-col cols="12" md="3"
                        ><v-text-field
                            v-model="form.search"
                            label="Search teams or game ID"
                            hide-details
                            clearable
                    /></v-col>
                    <v-col cols="12" sm="6" md="2"
                        ><v-select
                            v-model="form.status"
                            label="Status"
                            :items="[
                                { title: 'All', value: '' },
                                { title: 'Scheduled', value: 'scheduled' },
                                { title: 'Ongoing', value: 'ongoing' },
                                { title: 'Finished', value: 'finished' },
                            ]"
                            hide-details
                    /></v-col>
                    <v-col cols="6" md="2"
                        ><v-text-field
                            v-model="form.date_from"
                            type="date"
                            label="From (Manila)"
                            :error-messages="form.errors.date_from"
                    /></v-col>
                    <v-col cols="6" md="2"
                        ><v-text-field
                            v-model="form.date_to"
                            type="date"
                            label="To (Manila)"
                            :error-messages="form.errors.date_to"
                    /></v-col>
                    <v-col cols="12" md="3" class="d-flex align-start ga-2"
                        ><v-btn type="submit" color="primary" :loading="form.processing"
                            >Filter</v-btn
                        ><v-btn variant="text" @click="router.visit(index())">Reset</v-btn></v-col
                    >
                </v-row>
            </form>
            <v-table class="ending-table">
                <thead>
                    <tr>
                        <th>Game</th>
                        <th>Matchup</th>
                        <th>League</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Betting</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="game in games.data" :key="game.id">
                        <td>#{{ game.id }}</td>
                        <td>
                            <div class="d-flex align-center ga-2 py-3">
                                <EntityLogo
                                    :src="game.team_a_logo"
                                    :name="game.team_a_name"
                                    :size="28"
                                />{{ game.team_a_name }} <span class="text-medium-emphasis">vs</span
                                ><EntityLogo
                                    :src="game.team_b_logo"
                                    :name="game.team_b_name"
                                    :size="28"
                                />{{ game.team_b_name }}
                            </div>
                        </td>
                        <td>{{ game.league }}</td>
                        <td>{{ displayDate(game.schedule) }}</td>
                        <td>{{ game.status }}</td>
                        <td>{{ game.betting }}</td>
                        <td>
                            <v-btn variant="text" size="small" @click="router.visit(show(game.id))"
                                >View game</v-btn
                            >
                        </td>
                    </tr>
                    <tr v-if="!games.data.length">
                        <td colspan="7" class="text-center pa-6">
                            No Lucky Four games match these filters.
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="games" selectable />
        </v-card>
    </LuckyFourShell>
</template>
