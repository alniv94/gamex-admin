<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
defineProps<{ title: string; ready: boolean; subtitle?: string }>();
const page = usePage();
</script>

<template>
    <div>
        <Head :title="title" />
        <div class="players-page agents-page">
            <div class="players-breadcrumb">Agents</div>
            <div class="agents-heading">
                <div>
                    <h1 class="players-title">{{ title }}</h1>
                    <p v-if="subtitle" class="agents-subtitle">{{ subtitle }}</p>
                </div>
                <div v-if="$slots.actions" class="agents-heading-actions">
                    <slot name="actions" />
                </div>
            </div>
            <v-alert
                v-if="page.flash.success"
                type="success"
                variant="tonal"
                class="mb-4"
                role="status"
                >{{ page.flash.success }}</v-alert
            >
            <v-alert v-if="!ready" type="info" variant="tonal" class="mb-4"
                >Agent records are being prepared. Changes will be available once the import is
                verified.</v-alert
            >
            <slot />
        </div>
    </div>
</template>

<style scoped>
.agents-page {
    color: #243449;
}
.agents-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin: 12px 0 24px;
}
.agents-heading .players-title {
    margin: 0;
    font-size: 24px;
    letter-spacing: -0.5px;
    color: #172b42;
}
.agents-subtitle {
    margin: 5px 0 0;
    font-size: 13px;
    line-height: 1.6;
    color: #68788c;
}
.agents-heading-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.agents-page :deep(.v-btn) {
    text-transform: none;
    letter-spacing: 0;
    font-weight: 500;
    font-size: 13px;
}
.agents-page :deep(.agents-panel) {
    border: 1px solid #e5eaf0;
    border-radius: 12px !important;
}
.agents-page :deep(.ending-table) {
    color: #34445a;
    font-size: 13px;
    --v-table-row-height: 60px;
    --v-table-header-height: 46px;
}
.agents-page :deep(.ending-table th) {
    background: #f8fafc;
    color: #64748b;
    font-weight: 500 !important;
    font-size: 12px;
    text-transform: none;
    border-block: 1px solid #e8edf3;
}
.agents-page :deep(.ending-table td) {
    padding-block: 12px !important;
    border-color: #edf1f5;
}
.agents-page :deep(.ending-table tbody tr:hover) {
    background: #f8fcfe;
}
.agents-page :deep(.ending-table .v-chip) {
    font-size: 11px;
    font-weight: 500;
}
.agents-page :deep(.ending-table a) {
    color: #007ba8;
    text-decoration: none;
    font-weight: 500;
}
.agents-page :deep(.ending-table a:hover) {
    text-decoration: underline;
}
.agents-page :deep(.ending-table th button) {
    appearance: none;
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    padding: 8px 0;
    cursor: pointer;
}
.agents-page :deep(.ending-table th button:focus-visible) {
    outline: 2px solid #007ba8;
    outline-offset: 3px;
    border-radius: 3px;
}
@media (max-width: 600px) {
    .agents-page {
        padding: 20px 12px;
    }
    .agents-heading {
        align-items: stretch;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 20px;
    }
    .agents-heading .players-title {
        font-size: 22px;
    }
    .agents-heading-actions > :deep(*) {
        flex: 1;
    }
}
</style>
