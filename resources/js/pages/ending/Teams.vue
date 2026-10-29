<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EndingCatalogController from '@/actions/App/Http/Controllers/EndingCatalogController';
import EndingShell from '../../components/ending/EndingShell.vue';
import EndingToolbar from '../../components/ending/EndingToolbar.vue';
import EndingPager from '../../components/ending/EndingPager.vue';
import EntityLogo from '../../components/ending/EntityLogo.vue';
import CatalogForm from '../../components/ending/CatalogForm.vue';
import { useEndingSearch, exportEndingRows } from '../../composables/useEndingTable';
import { displayDate } from '../../types/ending';
import type { Team, League, PageResult } from '../../types/ending';
const props = defineProps<{
    records: PageResult<Team>;
    leagues: League[];
    filters: { search?: string };
    migrationReady: boolean;
}>();
const { search, filter } = useEndingSearch(
    EndingCatalogController.teams.url(),
    props.filters.search,
    () => props.records.per_page,
);
const dialog = ref(false);
const selected = ref<Team | null>(null);
function edit(team: Team | null) {
    selected.value = team;
    dialog.value = true;
}
function exportRows() {
    exportEndingRows('ending-teams.csv', [
        ['League', 'Name', 'Alias', 'Logo', 'Status', 'Created at'],
        ...props.records.data.map((team) => [
            team.league?.league,
            team.team_name,
            team.team_alias,
            team.logo,
            team.is_active,
            displayDate(team.created_at || null),
        ]),
    ]);
}
</script>

<template>
    <EndingShell title="Teams" :migration-ready="migrationReady">
        <v-card>
            <EndingToolbar
                v-model="search"
                create-label="Create Team"
                :disabled="!migrationReady"
                exportable
                @create="edit(null)"
                @refresh="router.reload()"
                @search="filter"
                @export="exportRows"
            />
            <v-divider />
            <v-table class="ending-table" density="compact"
                ><thead>
                    <tr>
                        <th class="text-center">Action</th>
                        <th>League</th>
                        <th>Logo</th>
                        <th>Name</th>
                        <th>Alias</th>
                        <th>Status</th>
                        <th>Created at <v-icon icon="mdi-arrow-down" size="12" /></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="team in records.data" :key="team.id">
                        <td class="text-center">
                            <v-btn
                                icon="mdi-square-edit-outline"
                                color="primary"
                                size="x-small"
                                variant="text"
                                :aria-label="`Edit ${team.team_name}`"
                                :disabled="!migrationReady"
                                @click="edit(team)"
                            />
                        </td>
                        <td>{{ team.league?.league || '—' }}</td>
                        <td><EntityLogo :src="team.logo" :name="team.team_name" /></td>
                        <td>{{ team.team_name }}</td>
                        <td>{{ team.team_alias || '—' }}</td>
                        <td>
                            <v-chip
                                :color="team.is_active === 'Active' ? 'success' : 'default'"
                                size="x-small"
                                >{{ team.is_active }}</v-chip
                            >
                        </td>
                        <td class="text-no-wrap">{{ displayDate(team.created_at || null) }}</td>
                    </tr>
                    <tr v-if="!records.data.length">
                        <td colspan="7" class="text-center pa-8">No teams found.</td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="records" selectable />
        </v-card>
        <CatalogForm v-model="dialog" kind="team" :team="selected" :leagues="leagues" />
    </EndingShell>
</template>
