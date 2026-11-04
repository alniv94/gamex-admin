<script setup lang="ts">
import { index } from '@/actions/App/Http/Controllers/PlayerController';
import { Head, Link } from '@inertiajs/vue3';
defineProps<{ title: string; details?: boolean }>();
</script>

<template>
    <div>
        <Head :title="title" />
        <div class="players-page">
            <div class="players-breadcrumb">
                Players<template v-if="details">
                    &nbsp; &gt; &nbsp; <Link :href="index()">Player List</Link></template
                >
            </div>
            <h1 class="players-title">{{ title }}</h1>
            <slot />
        </div>
    </div>
</template>
