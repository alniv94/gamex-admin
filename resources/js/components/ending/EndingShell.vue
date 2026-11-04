<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
withDefaults(defineProps<{ title: string; migrationReady?: boolean }>(), {
    migrationReady: undefined,
});
</script>

<template>
    <div>
        <Head :title="title" />
        <div class="ending-page">
            <div class="ending-breadcrumb">Ending Game</div>
            <h1 class="ending-page-title">{{ title }}</h1>
            <v-alert v-if="migrationReady === false" type="info" variant="tonal" class="mb-4"
                >Historical ending games are being prepared. Changes become available after the
                import is verified.</v-alert
            >
            <slot />
        </div>
    </div>
</template>
