<script setup lang="ts">
import { index } from '@/actions/App/Http/Controllers/PlayerController';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
defineProps<{ title: string; details?: boolean }>();
const page = usePage();
const dismissed = ref('');
const message = computed(() => (typeof page.flash.success === 'string' ? page.flash.success : ''));
</script>

<template>
    <Head :title="title" />
    <AdminLayout>
        <div class="players-page">
            <div class="players-breadcrumb">
                Players<template v-if="details">
                    &nbsp; &gt; &nbsp; <Link :href="index()">Player List</Link></template
                >
            </div>
            <h1 class="players-title">{{ title }}</h1>
            <v-alert
                v-if="message && dismissed !== message"
                type="success"
                variant="tonal"
                closable
                class="mb-4"
                @click:close="dismissed = message"
                >{{ message }}</v-alert
            >
            <slot />
        </div>
    </AdminLayout>
</template>
