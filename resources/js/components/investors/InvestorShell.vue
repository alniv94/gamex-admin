<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
defineProps<{ title: string; embedded?: boolean }>();
const page = usePage();
</script>

<template>
    <div>
        <Head :title="title" />
        <div class="players-page investors-page" :class="{ 'investors-page-embedded': embedded }">
            <div v-if="!embedded" class="players-breadcrumb">Investors</div>
            <h1 v-if="!embedded" class="players-title">{{ title }}</h1>
            <v-alert
                v-if="page.flash.success"
                type="success"
                variant="tonal"
                class="mb-4"
                role="status"
            >
                {{ page.flash.success }}
            </v-alert>
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
