<script setup lang="ts">
import { computed, ref, watch } from 'vue';
const props = withDefaults(
    defineProps<{ src?: string | null; name?: string | null; size?: number }>(),
    { size: 30 },
);
const failed = ref(false);
const source = computed(() =>
    props.src && /^(https?:\/\/|\/(?!\/))/i.test(props.src) ? props.src : null,
);
watch(
    () => props.src,
    () => {
        failed.value = false;
    },
);
</script>

<template>
    <span class="ending-logo" :style="{ width: `${size}px`, height: `${size}px` }">
        <img
            v-if="source && !failed"
            :src="source"
            :alt="name ? `${name} logo` : 'Logo'"
            loading="lazy"
            referrerpolicy="no-referrer"
            @error="failed = true"
        />
        <span
            v-else
            class="ending-logo-fallback"
            :aria-label="name ? `${name} logo` : 'Logo unavailable'"
            >{{
                (name || '?')
                    .split(/\s+/)
                    .map((word) => word[0])
                    .slice(0, 2)
                    .join('')
            }}</span
        >
    </span>
</template>
