<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
defineProps<{ title: string; migrationReady: boolean }>();
const page = usePage();
const success = computed(() => (typeof page.flash.success === 'string' ? page.flash.success : ''));
</script>
<template>
    <AdminLayout>
        <Head :title="'Lucky Four · ' + title" />
        <div class="ending-page lucky-four-shell">
            <header class="lucky-four-page-heading">
                <div>
                    <div class="lucky-four-breadcrumb">
                        <v-icon icon="mdi-numeric-4-box-outline" size="16" />
                        Lucky Four
                    </div>
                    <h1 class="ending-page-title mt-0">{{ title }}</h1>
                </div>
                <div v-if="$slots.actions" class="lucky-four-page-actions">
                    <slot name="actions" />
                </div>
            </header>
            <v-alert v-if="success" type="success" variant="tonal" class="mb-4">{{
                success
            }}</v-alert>
            <v-alert v-if="page.props.errors.action" type="error" variant="tonal" class="mb-4">{{
                page.props.errors.action
            }}</v-alert>
            <v-alert v-if="!migrationReady" type="info" variant="tonal" class="mb-4"
                >Lucky Four history is being prepared. Changes become available after the import is
                verified.</v-alert
            >
            <slot />
        </div>
    </AdminLayout>
</template>

<style scoped>
.lucky-four-shell {
    max-width: 1560px;
    margin-inline: auto;
    padding: 28px 32px 40px;
}
.lucky-four-page-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}
.lucky-four-breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 4px;
    font-size: 12px;
    font-weight: 500;
    color: #64748b;
}
.lucky-four-page-heading .ending-page-title {
    margin-bottom: 0;
    font-size: 26px;
    line-height: 1.4;
    letter-spacing: -0.7px;
    color: #172b4d;
}
.lucky-four-page-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
@media (max-width: 600px) {
    .lucky-four-shell {
        padding: 20px 16px 32px;
    }
    .lucky-four-page-heading {
        margin-bottom: 20px;
    }
    .lucky-four-page-actions {
        width: 100%;
    }
}
</style>
