<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import type { PageResult } from '../../types/ending';
withDefaults(
    defineProps<{ page: PageResult<unknown>; selectable?: boolean; pageSizes?: number[] }>(),
    { pageSizes: () => [5, 10, 25, 50, 100] },
);
const currentPage = usePage();
function resize(value: number) {
    const url = new URL(currentPage.url, window.location.origin);
    url.searchParams.set('per_page', String(value));
    url.searchParams.delete('page');
    router.visit(url.pathname + url.search, { preserveState: true, preserveScroll: true });
}
function visit(url: string | null) {
    if (url) router.visit(url, { preserveState: true, preserveScroll: true });
}
</script>
<template>
    <div class="admin-table-pager" aria-label="Table pagination">
        <template v-if="selectable">
            <span class="text-caption">Rows per page:</span>
            <v-select
                :model-value="page.per_page"
                :items="pageSizes"
                aria-label="Rows per page"
                variant="outlined"
                density="compact"
                hide-details
                class="ending-page-size"
                @update:model-value="resize"
            />
        </template>
        <span class="text-caption mr-2" aria-live="polite"
            >{{ page.from || 0 }}–{{ page.to || 0 }} of {{ page.total.toLocaleString() }}</span
        >
        <v-btn
            size="small"
            icon="mdi-chevron-left"
            variant="text"
            :disabled="!page.prev_page_url"
            aria-label="Previous page"
            @click="visit(page.prev_page_url)"
        />
        <v-btn
            size="small"
            icon="mdi-chevron-right"
            variant="text"
            :disabled="!page.next_page_url"
            aria-label="Next page"
            @click="visit(page.next_page_url)"
        />
    </div>
</template>
