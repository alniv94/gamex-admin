<script setup lang="ts">
import { ref } from 'vue';

const open = defineModel<boolean>({ default: false });
withDefaults(defineProps<{ loading?: boolean; resettable?: boolean }>(), { resettable: true });
const emit = defineEmits<{ apply: []; reset: [] }>();
const form = ref<{ validate: () => Promise<{ valid: boolean }> }>();
async function submit() {
    if ((await form.value?.validate())?.valid) emit('apply');
}
</script>

<template>
    <v-dialog
        v-model="open"
        max-width="560"
        :persistent="loading"
        class="admin-filter-dialog"
        aria-label="Filters"
    >
        <v-card class="admin-filter-card">
            <v-card-title class="admin-filter-header">
                <v-icon icon="mdi-filter-variant" color="primary" size="20" />
                <h2 class="admin-filter-heading">Filters</h2>
                <v-spacer />
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    width="32"
                    height="32"
                    aria-label="Close filters"
                    :disabled="loading"
                    @click="open = false"
                />
            </v-card-title>
            <v-divider />
            <v-form ref="form" class="admin-filter-form" @submit.prevent="submit">
                <v-card-text class="admin-filter-fields"><slot /></v-card-text>
                <v-divider />
                <v-card-actions class="admin-filter-actions">
                    <v-btn
                        v-if="resettable"
                        prepend-icon="mdi-filter-remove-outline"
                        color="#9a6700"
                        variant="text"
                        :disabled="loading"
                        @click="emit('reset')"
                        >Reset filters</v-btn
                    >
                    <v-spacer />
                    <v-btn color="error" variant="text" :disabled="loading" @click="open = false"
                        >Cancel</v-btn
                    >
                    <v-btn type="submit" color="primary" variant="flat" :loading="loading"
                        >Apply filters</v-btn
                    >
                </v-card-actions>
            </v-form>
        </v-card>
    </v-dialog>
</template>
