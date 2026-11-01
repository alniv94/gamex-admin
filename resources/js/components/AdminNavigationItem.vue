<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { VListItem } from 'vuetify/components';

defineProps<{
    title: string;
    icon: string;
    href: string;
    active: boolean;
    rail: boolean;
}>();

const page = usePage();
</script>

<template>
    <Link
        :as="VListItem"
        :href="href"
        :active="active"
        :aria-current="active ? 'page' : undefined"
        :aria-label="title"
        :prepend-gap="rail ? 0 : 16"
        :class="rail ? 'px-6' : 'pe-4'"
        @before="() => page.url !== href"
    >
        <template #prepend>
            <v-icon :icon="icon" color="primary" size="22" />
        </template>
        <v-list-item-title v-show="!rail" class="text-body-2 font-weight-regular">
            {{ title }}
        </v-list-item-title>
        <v-tooltip activator="parent" location="end" :text="title" :open-delay="rail ? 150 : 500" />
    </Link>
</template>
