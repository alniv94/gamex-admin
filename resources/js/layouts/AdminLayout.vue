<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import SettingsUserController from '@/actions/App/Http/Controllers/SettingsUserController';
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import EndingCatalogController from '@/actions/App/Http/Controllers/EndingCatalogController';
import { index as cashierIndex } from '@/actions/App/Http/Controllers/PlayerCashierController';
import {
    index as playerList,
    withdrawalAccounts,
} from '@/actions/App/Http/Controllers/PlayerController';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useDisplay } from 'vuetify';
import BrandLogo from '../components/BrandLogo.vue';
import { useAdminApi } from '../composables/useAdminApi';
import type { AdminPageProps } from '../types';

const page = usePage<AdminPageProps>();
const { can, canAny } = usePermissions();
const user = computed(() => page.props.auth.user);
const endingNavigation = computed(() =>
    [
        {
            title: 'Scheduled Games',
            icon: 'scheduled-games',
            permission: 'Ending Games',
            route: EndingGameController.index(),
        },
        {
            title: 'Teams',
            icon: 'teams',
            permission: 'Ending Teams',
            route: EndingCatalogController.teams(),
        },
        {
            title: 'Leagues',
            icon: 'leagues',
            permission: 'Ending Leagues',
            route: EndingCatalogController.leagues(),
        },
        {
            title: 'Config',
            icon: 'config',
            permission: 'Ending Config',
            route: EndingCatalogController.configuration(),
        },
    ].filter((item) => can(item.permission)),
);
const firstName = computed(() => user.value?.name?.trim().split(/\s+/)[0] || 'Administrator');
const { width } = useDisplay();
const drawer = ref(width.value >= 1264);
const openedGroups = ref(['players', 'settings']);
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
            <v-list v-model:opened="openedGroups" class="legacy-navigation pt-0">
                <v-list-item
                    href="/dashboard"
                    color="primary"
                    :active="page.url.startsWith('/dashboard')"
                    variant="text"
                    @click.prevent="dashboard"
                >
                    <template #prepend
                        ><img src="/images/navigation/dashboard.png" alt="" class="navigation-icon"
                    /></template>
                    <v-list-item-title>Dashboard</v-list-item-title>
                </v-list-item>
                <v-list-group v-if="canAny(['Player List', 'Cash In', 'Cash Out'])" value="players">
                    <template #activator="{ props }"
                        ><v-list-item
                            v-bind="props"
                            color="primary"
                            :active="
                                page.url.startsWith('/players') || page.url.startsWith('/player/')
                            "
                            ><template #prepend
                                ><v-icon
                                    icon="mdi-account-group"
                                    color="#0097a7"
                                    class="navigation-icon" /></template
                            ><v-list-item-title>Players</v-list-item-title></v-list-item
                        ></template
                    >
                    <v-list-item
                        v-if="can('Player List')"
                        :href="playerList.url()"
                        color="primary"
                        :active="
                            page.url.startsWith('/players/list') || page.url.startsWith('/player/')
                        "
                        @click.prevent="
                            router.visit(playerList());
                            drawer = width >= 1264;
                        "
                        ><template #prepend
                            ><img
                                src="/images/navigation/player-list.png"
                                alt=""
                                class="navigation-icon" /></template
                        ><v-list-item-title>Player List</v-list-item-title></v-list-item
                    >
                    <v-list-item
                        v-if="can('Player List')"
                        :href="withdrawalAccounts.url()"
                        color="primary"
                        :active="page.url.split('?')[0] === withdrawalAccounts.url()"
                        @click.prevent="
                            router.visit(withdrawalAccounts());
                            drawer = width >= 1264;
                        "
                    >
                        <template #prepend>
                            <img
                                src="/images/navigation/player-list.png"
                                alt=""
                                class="navigation-icon"
                            />
                        </template>
                        <v-list-item-title>Player Withdrawal Accounts</v-list-item-title>
                    </v-list-item>
                    <v-list-item
                        v-for="item in (
                            [
                                {
                                    kind: 'cashin',
                                    title: 'Cash In',
                                    icon: 'mdi-cash-plus',
                                    permission: 'Cash In',
                                },
                                {
                                    kind: 'cashout',
                                    title: 'Cash Out',
                                    icon: 'mdi-cash-minus',
                                    permission: 'Cash Out',
                                },
                            ] as const
                        ).filter((item) => can(item.permission))"
                        :key="item.kind"
                        :href="cashierIndex.url(item.kind)"
                        color="primary"
                        :active="page.url.split('?')[0] === cashierIndex.url(item.kind)"
                        @click.prevent="
                            router.visit(cashierIndex(item.kind));
                            drawer = width >= 1264;
                        "
                    >
                        <template #prepend
                            ><v-icon :icon="item.icon" color="primary" class="navigation-icon"
                        /></template>
                        <v-list-item-title>{{ item.title }}</v-list-item-title>
                    </v-list-item>
                </v-list-group>
                <v-list-subheader v-if="endingNavigation.length" class="mt-3 font-weight-bold"
                    >Ending Game</v-list-subheader
                >
                <v-list-item
                    v-for="item in endingNavigation"
                    :key="item.icon"
                    :href="item.route.url"
                    color="primary"
                    :active="page.url.split('?')[0].startsWith(item.route.url)"
                    variant="text"
                    @click.prevent="
                        router.visit(item.route);
                        drawer = width >= 1264;
                    "
                >
                    <template #prepend
                        ><img
                            :src="`/images/navigation/${item.icon}.png`"
                            alt=""
                            class="navigation-icon"
                    /></template>
                    <v-list-item-title>{{ item.title }}</v-list-item-title>
                </v-list-item>
                <v-list-group v-if="canAny(['Users', 'Manage users and roles'])" value="settings">
                    <template #activator="{ props }">
                        <v-list-item
                            v-bind="props"
                            color="primary"
                            :active="page.url.startsWith('/settings')"
                        >
                            <template #prepend
                                ><v-icon
                                    icon="mdi-cog-outline"
                                    color="primary"
                                    class="navigation-icon"
                            /></template>
                            <v-list-item-title>Settings</v-list-item-title>
                        </v-list-item>
                    </template>
                    <v-list-item
                        :href="SettingsUserController.index.url()"
                        color="primary"
                        :active="page.url.startsWith('/settings/users')"
                        @click.prevent="
                            router.visit(SettingsUserController.index());
                            drawer = width >= 1264;
                        "
                    >
                        <template #prepend
                            ><v-icon
                                icon="mdi-account-cog-outline"
                                color="primary"
                                class="navigation-icon"
                        /></template>
                        <v-list-item-title>Users</v-list-item-title>
                    </v-list-item>
                </v-list-group>
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
