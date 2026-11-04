<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onUnmounted, ref } from 'vue';

const messages = ref<string[]>([]);

onUnmounted(
    router.on('flash', (event) => {
        const message = event.detail.flash.success;
        if (typeof message === 'string' && message.trim()) messages.value.push(message);
    }),
);
</script>

<template>
    <v-snackbar-queue
        v-model="messages"
        color="success"
        location="top end"
        prepend-icon="mdi-check-circle"
        :timeout="5000"
        min-width="0"
        max-width="420"
        width="calc(100vw - 32px)"
    >
        <template #actions="{ props }">
            <v-btn
                v-bind="props"
                icon="mdi-close"
                variant="text"
                size="small"
                aria-label="Dismiss notification"
            />
        </template>
    </v-snackbar-queue>
</template>
