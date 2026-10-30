<script setup lang="ts">
import { ref } from 'vue';
import type { PlayerRecord } from '../../types/players';
import PlayerStatus from './PlayerStatus.vue';
defineProps<{ player: PlayerRecord }>();
const open = ref(false);
const fields = [
    ['Marital Status', 'marital_status'],
    ['Date of Birth', 'birthdate'],
    ['Nationality', 'nationality'],
    ['Source of Income', 'source_of_income'],
    ['Nature of Work', 'nature_of_work'],
    ['Nature of Business', 'nature_of_business'],
    ['Address', 'address'],
] as const;
</script>
<template>
    <v-menu v-model="open" location="bottom end" :close-on-content-click="false">
        <template #activator="{ props }"
            ><v-btn
                v-bind="props"
                icon="mdi-dots-vertical"
                variant="text"
                size="small"
                color="grey"
                :aria-label="`Other details for ${player.name}`"
        /></template>
        <v-card class="player-other-details pa-6" width="400" max-width="90vw">
            <div class="d-flex justify-space-between align-center mb-4">
                <strong>Other Details</strong
                ><v-btn variant="text" size="small" @click="open = false">Close</v-btn>
            </div>
            <dl class="player-information">
                <div v-for="[label, key] in fields" :key="key">
                    <dt>{{ label }}</dt>
                    <dd>{{ player[key] || '-' }}</dd>
                </div>
                <div>
                    <dt>Active Status</dt>
                    <dd><PlayerStatus :status="player.active_status" /></dd>
                </div>
            </dl>
        </v-card>
    </v-menu>
</template>
