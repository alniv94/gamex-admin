<script setup lang="ts">
import AdminTableToolbar from '../AdminTableToolbar.vue';
import type { ActiveTableFilter } from '@/composables/useTableFilters';
const search = defineModel<string | null>({ default: '' });
defineProps<{
    createLabel?: string;
    disabled?: boolean;
    exportable?: boolean;
    title?: string;
    total?: number;
    exportLabel?: string;
    filterable?: boolean;
    filterLabel?: string;
    filtersOpen?: boolean;
    activeFilters?: ActiveTableFilter[];
}>();
defineEmits<{
    create: [];
    refresh: [];
    search: [];
    export: [];
    filter: [];
    removeFilter: [key: string];
    resetFilters: [];
}>();
</script>

<template>
    <AdminTableToolbar
        v-model="search"
        :title="title"
        :total="total"
        :filterable="filterable"
        :filter-label="filterLabel"
        :filters-open="filtersOpen"
        :active-filters="activeFilters"
        :exports="
            exportable
                ? [{ title: exportLabel || 'Current page (CSV)', action: () => $emit('export') }]
                : []
        "
        @refresh="$emit('refresh')"
        @search="$emit('search')"
        @filter="$emit('filter')"
        @remove-filter="$emit('removeFilter', $event)"
        @reset-filters="$emit('resetFilters')"
    >
        <template #actions>
            <v-btn
                v-if="createLabel"
                prepend-icon="mdi-plus"
                color="primary"
                variant="flat"
                size="small"
                :aria-label="createLabel"
                :title="createLabel"
                :disabled="disabled"
                @click="$emit('create')"
                >{{ createLabel }}</v-btn
            >
        </template>
        <slot />
    </AdminTableToolbar>
</template>
