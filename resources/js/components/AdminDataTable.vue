<script setup lang="ts" generic="T extends object">
import { computed, ref, useAttrs } from 'vue';
import type { DataTableHeader } from 'vuetify';
import { router } from '@inertiajs/vue3';
import AdminTableToolbar from './AdminTableToolbar.vue';
import { exportTableItems, tableValue } from '@/composables/useTableExport';
import { buildActiveTableFilters } from '@/composables/useTableFilters';

defineOptions({ inheritAttrs: false });
const props = withDefaults(
    defineProps<{
        headers: readonly DataTableHeader<T>[];
        items: readonly T[];
        server?: boolean;
        toolbar?: boolean;
        title?: string;
        loading?: boolean;
        search?: string | null;
        searchLabel?: string;
        noDataText?: string;
        exportName?: string;
        refresh?: () => void;
    }>(),
    {
        toolbar: true,
        noDataText: 'No records match your search or filters.',
    },
);
const attrs = useAttrs();
const tableLabel = computed(() =>
    typeof attrs['aria-label'] === 'string' ? attrs['aria-label'] : props.title || 'Data table',
);
defineSlots<{
    [name: string]:
        | ((props: { item: T; index: number; [key: string]: unknown }) => unknown)
        | undefined;
    actions?: () => unknown;
}>();
const isServer = computed(() => props.server);
const localSearch = ref<string | null>('');
const search = computed(() => props.search ?? localSearch.value ?? '');
const exportItems = computed(() => {
    const query = search.value.toLocaleLowerCase();
    if (isServer.value || !query) return props.items;
    return props.items.filter((item) =>
        props.headers.some((header) => {
            const value =
                typeof header.value === 'function'
                    ? header.value(item)
                    : tableValue(
                          item,
                          typeof header.value === 'string' ? header.value : String(header.key),
                      );
            return String(value ?? '')
                .toLocaleLowerCase()
                .includes(query);
        }),
    );
});
const exports = computed(() => [
    {
        title: isServer.value ? 'Current page (CSV)' : 'All matching rows (CSV)',
        action: () =>
            exportTableItems(
                `${props.exportName || (props.title || 'records').toLowerCase().replace(/[^a-z0-9]+/g, '-')}.csv`,
                exportItems.value,
                props.headers,
            ),
    },
]);
const tableHeaders = computed(() =>
    props.headers.map((header) => ({
        ...header,
        sortable: isServer.value
            ? false
            : (header.sortable ?? !['action', 'actions'].includes(String(header.key))),
    })),
);
</script>

<template>
    <div class="admin-data-table" :aria-busy="loading">
        <AdminTableToolbar
            v-if="toolbar"
            v-model="localSearch"
            :title="title"
            :total="isServer ? undefined : exportItems.length"
            :search-label="searchLabel"
            :loading="loading"
            :searchable="!isServer"
            :exports="exports"
            :active-filters="isServer ? [] : buildActiveTableFilters({ search }, ['search'])"
            @remove-filter="localSearch = ''"
            @reset-filters="localSearch = ''"
            @refresh="refresh ? refresh() : router.reload()"
        >
            <template #actions><slot name="actions" /></template>
        </AdminTableToolbar>
        <v-data-table
            v-bind="attrs"
            :headers="tableHeaders"
            :items="items"
            :search="isServer ? undefined : search"
            :items-per-page="isServer ? -1 : 10"
            :items-per-page-options="[5, 10, 25, 50, 100]"
            items-per-page-text="Rows per page:"
            :show-first-last-page="false"
            :loading="loading"
            :disable-sort="loading"
            :no-data-text="noDataText"
            :mobile="false"
            :aria-label="tableLabel"
            :hide-default-footer="isServer"
            class="admin-table"
            density="comfortable"
            hover
        >
            <template v-for="(_, name) in $slots" :key="name" #[name]="slotProps">
                <slot :name="name" v-bind="slotProps || {}" />
            </template>
        </v-data-table>
    </div>
</template>
