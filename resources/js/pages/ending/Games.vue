<script setup lang="ts">
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import { router, usePage, Link } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref } from 'vue';
import EndingShell from '../../components/ending/EndingShell.vue';
import EndingToolbar from '../../components/ending/EndingToolbar.vue';
import EntityLogo from '../../components/ending/EntityLogo.vue';
import GameForm from '../../components/ending/GameForm.vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import { exportEndingRows } from '../../composables/useEndingTable';
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
    sort: props.filters.sort || 'schedule',
    direction: props.filters.direction || 'desc',
});
const create = ref(false);
const filtersOpen = ref(!!(form.league_id || form.status || form.date_from || form.date_to));
let timer: ReturnType<typeof setTimeout> | undefined;
function filter() {
    router.get(
        EndingGameController.index.url(),
        { ...form, per_page: props.games.per_page },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
function search() {
    clearTimeout(timer);
    timer = setTimeout(filter, 300);
}
onBeforeUnmount(() => clearTimeout(timer));
function reset() {
    Object.assign(form, { search: '', league_id: null, status: null, date_from: '', date_to: '' });
    filter();
}
function sort(column: string) {
    form.direction = form.sort === column && form.direction === 'desc' ? 'asc' : 'desc';
    form.sort = column;
    filter();
}
function exportRows() {
    exportEndingRows('ending-scheduled-games.csv', [
        [
            'ID',
            'League',
            'Team A',
            'Team B',
            'Schedule',
            'Betting',
            'Status',
            'Enabled at',
            'Created at',
        ],
        ...props.games.data.map((game) => [
            game.id,
            game.league,
            game.team_a_name,
            game.team_b_name,
            displayDate(game.schedule),
            game.betting,
            game.status,
            displayDate(game.enabled_betting_at),
            displayDate(game.created_at),
        ]),
    ]);
}
</script>

<template>
    <EndingShell title="Scheduled Games" :migration-ready="migrationReady">
        <v-card>
            <EndingToolbar
                v-model="form.search"
                :create-label="canCreate ? 'Create Game' : undefined"
                :disabled="!migrationReady"
                exportable
                @create="create = true"
                @refresh="router.reload()"
                @search="search"
                @export="exportRows"
            >
                <v-btn
                    icon="mdi-filter-variant"
                    variant="text"
                    size="small"
                    aria-label="Toggle game filters"
                    title="Filters"
                    :aria-expanded="filtersOpen"
                    :color="filtersOpen ? 'primary' : undefined"
                    @click="filtersOpen = !filtersOpen"
                />
                <v-btn
                    size="small"
                    variant="text"
                    aria-label="Historical Logs"
                    title="Historical Logs"
                    icon="mdi-history"
                    :href="EndingGameController.history.url()"
                    @click.prevent="router.visit(EndingGameController.history())"
                />
            </EndingToolbar>
            <v-expand-transition>
                <v-form v-if="filtersOpen" class="pa-4 pt-0" @submit.prevent="filter">
                    <v-row dense>
                        <v-col cols="12" sm="6" md="3"
                            ><v-select
                                v-model="form.league_id"
                                :items="leagues"
                                item-title="league"
                                item-value="id"
                                label="League"
                                variant="outlined"
                                density="compact"
                                clearable
                                hide-details
                        /></v-col>
                        <v-col cols="12" sm="6" md="3"
                            ><v-select
                                v-model="form.status"
                                :items="[
                                    { title: 'Scheduled', value: 'scheduled' },
                                    { title: 'Ongoing', value: 'ongoing' },
                                    { title: 'Finished', value: 'finished' },
                                ]"
                                label="Status"
                                variant="outlined"
                                density="compact"
                                clearable
                                hide-details
                        /></v-col>
                        <v-col cols="12" sm="6" md="3"
                            ><v-text-field
                                v-model="form.date_from"
                                label="From (Philippines)"
                                type="date"
                                hide-details
                        /></v-col>
                        <v-col cols="12" sm="6" md="3"
                            ><v-text-field
                                v-model="form.date_to"
                                label="To (Philippines)"
                                type="date"
                                hide-details
                        /></v-col>
                        <v-col cols="12" class="d-flex ga-2"
                            ><v-btn type="submit" color="primary" variant="outlined" size="small"
                                >Apply Filters</v-btn
                            ><v-btn variant="text" size="small" @click="reset">Reset</v-btn></v-col
                        >
                    </v-row>
                    <v-alert
                        v-if="Object.keys(page.props.errors).length"
                        type="error"
                        variant="tonal"
                        role="alert"
                        class="mt-3"
                        >{{ Object.values(page.props.errors).join(' ') }}</v-alert
                    >
                </v-form>
            </v-expand-transition>
            <v-divider />
            <v-table class="ending-table ending-games-table" density="compact">
                <thead>
                    <tr>
                        <th class="text-center">Action</th>
                        <th>League</th>
                        <th>Game</th>
                        <th>
                            <button class="ending-sort" @click="sort('schedule')">
                                Schedule
                                <v-icon
                                    :icon="
                                        form.sort === 'schedule' && form.direction === 'asc'
                                            ? 'mdi-arrow-up'
                                            : 'mdi-arrow-down'
                                    "
                                    size="12"
                                />
                            </button>
                        </th>
                        <th>Betting</th>
                        <th>Status</th>
                        <th>
                            <button class="ending-sort" @click="sort('enabled_betting_at')">
                                Enabled at
                                <v-icon
                                    :icon="
                                        form.sort === 'enabled_betting_at' &&
                                        form.direction === 'asc'
                                            ? 'mdi-arrow-up'
                                            : 'mdi-arrow-down'
                                    "
                                    size="12"
                                />
                            </button>
                        </th>
                        <th>
                            <button class="ending-sort" @click="sort('created_at')">
                                Created at
                                <v-icon
                                    :icon="
                                        form.sort === 'created_at' && form.direction === 'asc'
                                            ? 'mdi-arrow-up'
                                            : 'mdi-arrow-down'
                                    "
                                    size="12"
                                />
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="game in games.data" :key="game.id">
                        <td class="text-center">
                            <v-btn
                                icon="mdi-text-box-search-outline"
                                variant="text"
                                color="primary"
                                size="x-small"
                                :aria-label="'View game ' + game.id"
                                :href="EndingGameController.show.url(game.id)"
                                @click.prevent="router.visit(EndingGameController.show(game.id))"
                            />
                        </td>
                        <td>{{ game.league || '—' }}</td>
                        <td>
                            <Link
                                :href="EndingGameController.show.url(game.id)"
                                class="ending-game-link"
                                ><span class="ending-inline-team"
                                    ><EntityLogo
                                        :src="game.team_a_logo"
                                        :name="game.team_a_name"
                                        :size="26"
                                    />{{ game.team_a_name || 'Team #' + game.team_a }}</span
                                ><span class="ending-versus">vs</span
                                ><span class="ending-inline-team"
                                    ><EntityLogo
                                        :src="game.team_b_logo"
                                        :name="game.team_b_name"
                                        :size="26"
                                    />{{ game.team_b_name || 'Team #' + game.team_b }}</span
                                ></Link
                            >
                        </td>
                        <td class="text-no-wrap">{{ displayDate(game.schedule) }}</td>
                        <td>
                            <v-chip
                                size="x-small"
                                :color="game.betting === 'Enabled' ? 'success' : 'default'"
                                >{{ game.betting }}</v-chip
                            >
                        </td>
                        <td class="text-no-wrap">
                            <v-chip
                                size="x-small"
                                :color="
                                    game.game_status_id === 5
                                        ? 'success'
                                        : game.game_status_id
                                          ? 'primary'
                                          : 'warning'
                                "
                                >{{ game.status }}</v-chip
                            >
                        </td>
                        <td class="text-no-wrap">{{ displayDate(game.enabled_betting_at) }}</td>
                        <td class="text-no-wrap">{{ displayDate(game.created_at) }}</td>
                    </tr>
                    <tr v-if="!games.data.length">
                        <td colspan="8" class="text-center pa-8 text-medium-emphasis">
                            No games found.
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="games" selectable />
        </v-card>
        <GameForm v-model="create" :leagues="leagues" :teams="teams" :presets="presets" />
    </EndingShell>
</template>
