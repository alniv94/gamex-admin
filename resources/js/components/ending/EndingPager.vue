<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { PageResult } from '../../types/ending';
defineProps<{ page: PageResult<unknown> }>();
function visit(url: string | null) {
    if (url) router.visit(url, { preserveState: true, preserveScroll: true });
}
</script>
<template>
    <div class="d-flex align-center justify-end flex-wrap ga-2 pa-3">
        <span class="text-caption mr-2"
            >{{ page.total.toLocaleString() }} records · Page {{ page.current_page }} of
            {{ page.last_page }}</span
        >
        <v-btn
            size="small"
            variant="text"
            :disabled="!page.prev_page_url"
            aria-label="Previous page"
            @click="visit(page.prev_page_url)"
            >Previous</v-btn
        >
        <v-btn
            size="small"
            variant="text"
            :disabled="!page.next_page_url"
            aria-label="Next page"
            @click="visit(page.next_page_url)"
            >Next</v-btn
        >
    </div>
</template>
