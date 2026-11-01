<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import { useAdminApi } from '../composables/useAdminApi';
import { useAdminSidebar } from '../composables/useAdminSidebar';
import type { AdminPageProps } from '../types';

const page = usePage<AdminPageProps>();
const user = computed(() => page.props.auth.user);
const firstName = computed(() => user.value?.name?.trim().split(/\s+/)[0] || 'Administrator');
const { drawer, rail, expanded, toggleLabel, toggleIcon, toggleSidebar, expandSidebar } =
    useAdminSidebar();
const busy = ref(false);
const error = ref('');
const api = useAdminApi();

async function logout() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        await api('/api/logout');
        expandSidebar();
        router.clearHistory();
        router.visit('/login', { replace: true });
    } catch (failure) {
        error.value =
            failure instanceof Error ? failure.message : 'Unable to log out. Please try again.';
        busy.value = false;
    }
}
</script>

<template>
    <v-app>
        <AdminSidebar v-model="drawer" :rail="rail" @expand="expandSidebar" />
        <v-app-bar class="px-2 px-md-4" color="surface" height="64" elevation="2">
            <v-btn
                :icon="toggleIcon"
                :aria-label="toggleLabel"
                :aria-expanded="expanded"
                aria-controls="admin-navigation"
                variant="tonal"
                rounded="circle"
                size="36"
                @click="toggleSidebar"
            />
            <v-spacer />
            <v-menu location="bottom end" transition="slide-y-transition">
                <template #activator="{ props }">
                    <v-btn
                        v-bind="props"
                        class="account-menu-button"
                        variant="text"
                        :aria-label="'Account menu for ' + firstName"
                    >
                        <span class="account-menu-name">{{ firstName }}</span
                        ><v-icon icon="mdi-chevron-down" size="22" class="ml-2" />
                    </v-btn>
                </template>
                <v-list class="account-menu" role="menu" aria-label="Account actions">
                    <v-list-item :disabled="busy" title="Logout" role="menuitem" @click="logout" />
                </v-list>
            </v-menu>
        </v-app-bar>
        <v-main class="dashboard-main">
            <v-alert v-if="error" type="error" variant="tonal" class="ma-4" role="alert">{{
                error
            }}</v-alert>
            <slot />
        </v-main>
        <v-overlay
            :model-value="busy"
            persistent
            class="logout-overlay"
            :opacity="1"
            scrim="#212121"
        >
            <span>Logging out, please wait...</span
            ><v-progress-circular indeterminate color="white" aria-label="Logging out" />
        </v-overlay>
    </v-app>
</template>
