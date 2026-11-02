<script setup lang="ts">
import type { ActiveTableFilter } from '@/composables/useTableFilters';

export interface TableExport {
    title: string;
    href?: string;
    action?: () => void;
}

const search = defineModel<string | null>({ default: '' });
withDefaults(
    defineProps<{
        title?: string;
        total?: number;
        searchLabel?: string;
        searchPlaceholder?: string;
        searchable?: boolean;
        loading?: boolean;
        refreshLabel?: string;
        filterLabel?: string;
        exports?: TableExport[];
        filterable?: boolean;
        filtersOpen?: boolean;
        activeFilters?: ActiveTableFilter[];
    }>(),
    { searchLabel: 'Search', searchable: true, refreshLabel: 'Refresh', filterLabel: 'Filters' },
);
defineEmits<{
    search: [];
    refresh: [];
    filter: [];
    removeFilter: [key: string];
    resetFilters: [];
}>();
</script>

<template>
    <div class="admin-table-controls">
        <div class="admin-table-toolbar">
            <div class="admin-table-actions">
                <h2 v-if="title" class="admin-table-title">{{ title }}</h2>
                <v-chip
                    v-if="total !== undefined"
                    size="small"
                    variant="tonal"
                    class="text-medium-emphasis"
                    >{{ total.toLocaleString() }} {{ total === 1 ? 'record' : 'records' }}</v-chip
                >
                <slot name="actions" />
                <v-btn
                    icon="mdi-refresh"
                    variant="text"
                    size="small"
                    height="36"
                    width="36"
                    rounded="sm"
                    :aria-label="refreshLabel"
                    :title="refreshLabel"
                    :loading="loading"
                    @click="$emit('refresh')"
                />
                <v-menu v-if="exports?.length">
                    <template #activator="{ props: menuProps }">
                        <v-btn
                            v-bind="menuProps"
                            prepend-icon="mdi-download-outline"
                            variant="text"
                            size="small"
                            height="36"
                            rounded="sm"
                            :disabled="loading"
                            aria-label="Export data"
                            >Export</v-btn
                        >
                    </template>
                    <v-list density="compact" aria-label="Export options">
                        <v-list-item
                            v-for="option in exports"
                            :key="option.title"
                            :title="option.title"
                            :href="option.href"
                            @click="option.action?.()"
                        />
                    </v-list>
                </v-menu>
                <slot />
            </div>
            <div class="admin-table-search-controls">
                <v-text-field
                    v-if="searchable"
                    v-model="search"
                    :label="searchLabel"
                    :placeholder="searchPlaceholder"
                    :aria-label="searchLabel"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    color="primary"
                    rounded="sm"
                    hide-details
                    clearable
                    maxlength="100"
                    @update:model-value="$emit('search')"
                    @keydown.enter.prevent="$emit('search')"
                />
                <v-btn
                    v-if="filterable"
                    prepend-icon="mdi-filter-variant"
                    variant="tonal"
                    size="small"
                    color="primary"
                    height="36"
                    rounded="sm"
                    :aria-label="filterLabel"
                    :aria-expanded="filtersOpen"
                    aria-haspopup="dialog"
                    :disabled="loading"
                    @click="$emit('filter')"
                    >Filters
                    <span
                        v-if="activeFilters?.length"
                        class="admin-filter-count"
                        aria-hidden="true"
                        >{{ activeFilters.length }}</span
                    ></v-btn
                >
            </div>
        </div>
        <div
            v-if="activeFilters?.length"
            class="admin-active-filters"
            role="region"
            aria-label="Active filters"
            aria-live="polite"
        >
            <span class="admin-active-filters-label">Active filters</span>
            <v-chip
                v-for="filter in activeFilters"
                :key="filter.key"
                size="small"
                color="light-blue-darken-3"
                variant="tonal"
                :closable="filter.removable"
                :model-value="true"
                :close-label="`Remove ${filter.label} filter`"
                :disabled="loading"
                @update:model-value="$emit('removeFilter', filter.key)"
            >
                <span class="admin-active-filter-text" :title="`${filter.label}: ${filter.value}`"
                    >{{ filter.label }}: {{ filter.value }}</span
                >
            </v-chip>
            <v-btn
                size="small"
                color="#9a6700"
                variant="text"
                :disabled="loading"
                @click="$emit('resetFilters')"
                >Reset filters</v-btn
            >
        </div>
    </div>
</template>
