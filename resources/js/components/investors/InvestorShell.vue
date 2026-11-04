<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
defineProps<{ title: string; embedded?: boolean }>();
</script>

<template>
    <div>
        <Head :title="title" />
        <div class="players-page investors-page" :class="{ 'investors-page-embedded': embedded }">
            <div v-if="!embedded" class="players-breadcrumb">Investors</div>
            <h1 v-if="!embedded" class="players-title">{{ title }}</h1>
            <slot />
        </div>
    </div>
</template>

<style scoped>
.investors-page .players-title {
    font-size: 18px;
}
.investors-page-embedded {
    padding: 16px;
}
@media (max-width: 600px) {
    .investors-page-embedded {
        padding: 12px;
    }
}
</style>
