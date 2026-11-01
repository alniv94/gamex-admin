<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
defineProps<{ title: string; migrationReady?: boolean }>();
const page = usePage();
const success = computed(() => (typeof page.flash.success === 'string' ? page.flash.success : ''));
</script>
<template>
    <div>
        <Head :title="'Otso Panalo · ' + title" />
        <div class="ending-page otso-page">
            <div class="ending-breadcrumb">Otso Panalo Game</div>
            <h1 class="ending-page-title mt-0">{{ title }}</h1>
            <v-alert v-if="success" type="success" variant="tonal" class="mb-4">{{
                success
            }}</v-alert>
            <v-alert v-if="page.props.errors.action" type="error" variant="tonal" class="mb-4">{{
                page.props.errors.action
            }}</v-alert>
            <v-alert v-if="migrationReady === false" type="info" variant="tonal" class="mb-4"
                >Historical Otso Panalo games are being prepared. Changes become available after the
                import is verified.</v-alert
            >
            <slot />
        </div>
    </div>
</template>

<style scoped>
.otso-page {
    color: rgb(var(--v-theme-on-surface));
    font-size: 12px;
}
</style>
