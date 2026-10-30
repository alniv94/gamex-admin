<script setup lang="ts">
defineProps<{ status: string | null }>();
</script>
<template>
    <v-chip
        size="small"
        variant="tonal"
        :color="
            status === 'Verified' || status === 'Active'
                ? 'success'
                : status === 'Pending'
                  ? 'orange'
                  : status === 'Rejected' || status === 'Inactive'
                    ? 'error'
                    : 'blue'
        "
        >{{ status || '-' }}</v-chip
    >
</template>
