<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
withDefaults(defineProps<{ title: string; migrationReady?: boolean }>(), {
    migrationReady: undefined,
});
const page = usePage();
const dismissed = ref('');
const message = computed(() => (typeof page.flash.success === 'string' ? page.flash.success : ''));
</script>

<template>
    <Head :title="title" />
    <AdminLayout>
        <div class="ending-page">
            <div class="ending-breadcrumb">Ending Game</div>
            <h1 class="ending-page-title">{{ title }}</h1>
            <v-alert
                v-if="message && dismissed !== message"
                type="success"
                variant="tonal"
                closable
                class="mb-4"
                @click:close="dismissed = message"
                >{{ message }}</v-alert
            >
            <v-alert v-if="migrationReady === false" type="info" variant="tonal" class="mb-4"
                >Historical ending games are being prepared. Changes become available after the
                import is verified.</v-alert
            >
            <slot />
        </div>
    </AdminLayout>
</template>
