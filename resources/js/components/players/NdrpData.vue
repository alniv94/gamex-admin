<script setup lang="ts">
import { ndrp } from '@/actions/App/Http/Controllers/PlayerController';
import { onMounted, ref } from 'vue';
import { useAdminApi } from '../../composables/useAdminApi';
import type { NdrpResult } from '../../types/players';
const props = defineProps<{ playerId: number }>();
const api = useAdminApi();
const result = ref<NdrpResult>();
const loading = ref(true);
const photo = ref('');
const preview = ref(false);
const headers = [
    { title: 'First Name', key: 'firstName' },
    { title: 'Middle Name', key: 'middleName' },
    { title: 'Last Name', key: 'lastName' },
    { title: 'Birthdate', key: 'birthdate' },
    { title: 'Source', key: 'source' },
    { title: 'Photo Path', key: 'photoPath' },
];
function photoUrl(url: string) {
    return /^https:\/\//i.test(url) ? url : '';
}
async function load() {
    loading.value = true;
    try {
        result.value = await api<NdrpResult>(ndrp.url(props.playerId), {}, 'GET');
    } catch (failure) {
        result.value = {
            status: 'error',
            message:
                failure instanceof Error ? failure.message : 'Unable to load PAGCOR NDRP data.',
        };
    } finally {
        loading.value = false;
    }
}
onMounted(load);
</script>
<template>
    <v-card class="mb-3">
        <div class="player-section-title">PAGCOR NDRP Data</div>
        <v-skeleton-loader
            v-if="loading"
            type="table-heading, table-row"
            aria-label="Loading PAGCOR NDRP data"
        />
        <template v-else-if="result?.status === 'ready' && result.data">
            <div class="d-flex flex-wrap ga-2 px-4 pb-4">
                <v-chip size="small" color="primary" variant="outlined"
                    >Total Records: {{ result.data.totalNumberOfRecords || 0 }}</v-chip
                >
                <v-chip size="small" color="success" variant="outlined"
                    >GEL Records: {{ result.data.numberOfGelRecords || 0 }}</v-chip
                >
                <v-chip size="small" color="orange" variant="outlined"
                    >Guest Records: {{ result.data.numberOfGuestRecords || 0 }}</v-chip
                >
                <v-chip size="small" color="blue" variant="outlined"
                    >Govt Records: {{ result.data.numberOfGovtRecords || 0 }}</v-chip
                >
            </div>
            <v-data-table
                :headers="headers"
                :items="result.data.records"
                :items-per-page="10"
                :items-per-page-options="[10, 25, 50]"
                class="players-table ndrp-table"
                no-data-text="No matching records"
            >
                <template #item.photoPath="{ item }"
                    ><v-btn
                        v-if="photoUrl(item.photoPath)"
                        variant="text"
                        size="small"
                        :aria-label="`View photo for ${item.firstName}`"
                        @click="
                            photo = photoUrl(item.photoPath);
                            preview = true;
                        "
                        ><v-img
                            :src="photoUrl(item.photoPath)"
                            width="34"
                            height="40"
                            alt="NDRP photo" /></v-btn
                    ><span v-else>Unavailable</span></template
                >
            </v-data-table>
        </template>
        <div
            v-else
            class="d-flex align-center justify-space-between ga-4 px-4 pb-4 text-medium-emphasis"
            role="status"
        >
            <span>{{ result?.message }}</span
            ><v-btn v-if="result?.status === 'error'" variant="text" color="primary" @click="load"
                >Retry</v-btn
            >
        </div>
        <v-dialog v-model="preview" max-width="800"
            ><v-card class="pa-4"
                ><v-img :src="photo" max-height="75vh" alt="PAGCOR NDRP photo" />
                <div class="text-right mt-4">
                    <v-btn color="primary" variant="text" @click="preview = false">Close</v-btn>
                </div></v-card
            ></v-dialog
        >
    </v-card>
</template>
