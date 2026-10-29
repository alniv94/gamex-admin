<script setup lang="ts">
const search = defineModel<string>({ default: '' });
defineProps<{ createLabel?: string; disabled?: boolean; exportable?: boolean; title?: string }>();
defineEmits<{ create: []; refresh: []; search: []; export: [] }>();
</script>

<template>
    <div class="ending-toolbar">
        <div class="d-flex align-center ga-1">
            <h2 v-if="title" class="text-subtitle-1 font-weight-bold mr-2">{{ title }}</h2>
            <v-btn
                v-if="createLabel"
                icon="mdi-plus-box-outline"
                color="primary"
                variant="text"
                size="small"
                :aria-label="createLabel"
                :title="createLabel"
                :disabled="disabled"
                @click="$emit('create')"
            />
            <v-btn
                icon="mdi-sync"
                variant="text"
                size="small"
                aria-label="Refresh"
                title="Refresh"
                @click="$emit('refresh')"
            />
            <v-btn
                v-if="exportable"
                icon="mdi-file-export-outline"
                variant="text"
                size="small"
                aria-label="Export current page"
                title="Export current page"
                @click="$emit('export')"
            />
            <slot />
        </div>
        <v-text-field
            v-model="search"
            label="Search"
            prepend-inner-icon="mdi-magnify"
            variant="outlined"
            density="compact"
            hide-details
            clearable
            class="ending-search"
            @update:model-value="$emit('search')"
            @keyup.enter="$emit('search')"
        />
    </div>
</template>
