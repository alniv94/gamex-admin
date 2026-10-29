<script setup lang="ts">
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useDisplay } from 'vuetify';
import BrandLogo from '../components/BrandLogo.vue';
import { useAdminApi } from '../composables/useAdminApi';
import type { AdminPageProps } from '../types';

const page = usePage<AdminPageProps>();
const user = computed(() => page.props.auth.user);
const firstName = computed(() => user.value?.name?.trim().split(/\s+/)[0] || 'Administrator');
const { width } = useDisplay();
const drawer = ref(width.value >= 1264);
const busy = ref(false);
const error = ref('');
const api = useAdminApi();
function dashboard() {
    if (width.value < 1264) drawer.value = false;
    if (page.url !== '/dashboard') router.visit('/dashboard');
}
async function logout() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        await api('/api/logout');
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
        <v-navigation-drawer
            v-model="drawer"
            :width="256"
            :mobile-breakpoint="1264"
            :order="-1"
            class="legacy-drawer"
            aria-label="Main navigation"
        >
            <div class="drawer-brand"><BrandLogo /></div>
            <v-list class="legacy-navigation pt-0">
                <v-list-item
                    href="/dashboard"
                    color="primary"
                    :active="page.url.startsWith('/dashboard')"
                    variant="tonal"
                    @click.prevent="dashboard"
                >
                    <template #prepend
                        ><img src="/images/navigation/dashboard.png" alt="" class="navigation-icon"
                    /></template>
                    <v-list-item-title>Dashboard</v-list-item-title>
                </v-list-item>
                <v-list-item
                    v-if="user?.permissions.some((p) => p.id === 17)"
                    :href="EndingGameController.index.url()"
                    color="primary"
                    :active="page.url.startsWith('/ending')"
                    variant="tonal"
                    prepend-icon="mdi-basketball"
                    @click.prevent="
                        router.visit(EndingGameController.index());
                        drawer = width >= 1264;
                    "
                >
                    <v-list-item-title>Ending Games</v-list-item-title>
                </v-list-item>
            </v-list>
        </v-navigation-drawer>
        <v-app-bar class="legacy-app-bar" color="surface" height="64" flat>
            <v-btn
                class="drawer-toggle"
                :icon="drawer ? 'mdi-close' : 'mdi-menu'"
                :aria-label="drawer ? 'Close navigation' : 'Open navigation'"
                :aria-expanded="drawer"
                @click="drawer = !drawer"
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
