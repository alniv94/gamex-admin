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
import type { League, PageResult } from '../../types/ending';
const props = defineProps<{
    records: PageResult<League>;
    filters: { search?: string };
    migrationReady: boolean;
}>();
const { search, filter } = useEndingSearch(
    EndingCatalogController.leagues.url(),
    props.filters.search,
    () => props.records.per_page,
);
const dialog = ref(false);
const selected = ref<League | null>(null);
function edit(league: League | null) {
    selected.value = league;
    dialog.value = true;
}
function exportRows() {
    exportEndingRows('ending-leagues.csv', [
        ['League', 'Logo', 'Locality', 'Status', 'Created at'],
        ...props.records.data.map((league) => [
            league.league,
            league.logo,
            league.is_local,
            league.is_active,
            displayDate(league.created_at || null),
        ]),
    ]);
}
</script>

<template>
    <EndingShell title="Leagues" :migration-ready="migrationReady">
        <v-card>
            <EndingToolbar
                v-model="search"
                create-label="Create League"
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
                        <th>Is local</th>
                        <th>Status</th>
                        <th>Created at <v-icon icon="mdi-arrow-down" size="12" /></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="league in records.data" :key="league.id">
                        <td class="text-center">
                            <v-btn
                                icon="mdi-square-edit-outline"
                                color="primary"
                                size="x-small"
                                variant="text"
                                :aria-label="`Edit ${league.league}`"
                                :disabled="!migrationReady"
                                @click="edit(league)"
                            />
                        </td>
                        <td>{{ league.league }}</td>
                        <td><EntityLogo :src="league.logo" :name="league.league" /></td>
                        <td>{{ league.is_local }}</td>
                        <td>
                            <v-chip
                                :color="league.is_active === 'Active' ? 'success' : 'default'"
                                size="x-small"
                                >{{ league.is_active }}</v-chip
                            >
                        </td>
                        <td class="text-no-wrap">{{ displayDate(league.created_at || null) }}</td>
                    </tr>
                    <tr v-if="!records.data.length">
                        <td colspan="6" class="text-center pa-8">No leagues found.</td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="records" selectable />
        </v-card>
        <CatalogForm v-model="dialog" kind="league" :league="selected" />
    </EndingShell>
</template>
