<script setup lang="ts">
defineProps<{
    label: string;
    column: string;
    sort: string;
    direction: string;
    numeric?: boolean;
    disabled?: boolean;
}>();
defineEmits<{ sort: [column: string] }>();
</script>

<template>
    <th
        scope="col"
        :class="{ 'text-right': numeric }"
        :aria-sort="sort === column ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'"
    >
        <button
            type="button"
            class="agent-sort"
            :disabled="disabled"
            :title="`Sort by ${label.toLowerCase()}`"
            @click="$emit('sort', column)"
        >
            <span>{{ label }}</span>
            <v-icon size="15" :class="{ 'sort-active': sort === column }">{{
                sort === column
                    ? direction === 'desc'
                        ? 'mdi-arrow-down'
                        : 'mdi-arrow-up'
                    : 'mdi-unfold-more-horizontal'
            }}</v-icon>
        </button>
    </th>
</template>

<style scoped>
.agent-sort {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.agent-sort .v-icon {
    opacity: 0.4;
}
.agent-sort .sort-active {
    opacity: 1;
    color: #007ba8;
}
.agent-sort:hover {
    color: #007ba8;
}
</style>
