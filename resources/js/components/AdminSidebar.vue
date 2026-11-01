<script setup lang="ts">
import EndingCatalogController from '@/actions/App/Http/Controllers/EndingCatalogController';
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import {
    index as otsoGames,
    bets as otsoBets,
    configurations as otsoConfigs,
} from '@/actions/App/Http/Controllers/OtsoPanaloController';
import { index as cashierIndex } from '@/actions/App/Http/Controllers/PlayerCashierController';
import {
    index as playerList,
    withdrawalAccounts,
} from '@/actions/App/Http/Controllers/PlayerController';
import SettingsUserController from '@/actions/App/Http/Controllers/SettingsUserController';
import { index as playerReport } from '@/actions/App/Http/Controllers/PlayerReportController';
import { usePermissions } from '@/composables/usePermissions';
import { dashboard } from '@/routes';
import type { AdminPageProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useDisplay } from 'vuetify';
import AdminNavigationItem from './AdminNavigationItem.vue';
import BrandLogo from './BrandLogo.vue';

defineProps<{ rail: boolean }>();
const drawer = defineModel<boolean>({ required: true });
const emit = defineEmits<{ expand: [] }>();
const page = usePage<AdminPageProps>();
const { can, canAny } = usePermissions();
const { mobile } = useDisplay();
const openedGroups = ref(['players', 'ending', 'otso', 'settings']);
const currentPath = computed(() => page.url.split(/[?#]/)[0]);
const playerNavigation = computed(() =>
    [
        {
            title: 'Player List',
            icon: 'mdi-format-list-bulleted',
            permission: 'Player List',
            route: playerList(),
            active: isActive(playerList.url()) || isActive('/player'),
        },
        {
            title: 'Player Withdrawal Accounts',
            icon: 'mdi-bank-outline',
            permission: 'Player List',
            route: withdrawalAccounts(),
        },
        {
            title: 'Registered Player List',
            icon: 'mdi-account-group-outline',
            permission: 'Player List',
            route: playerReport('registered'),
        },
        {
            title: 'Newly Registered Players',
            icon: 'mdi-account-plus-outline',
            permission: 'Player List',
            route: playerReport('new'),
        },
        {
            title: 'Active Players Summary',
            icon: 'mdi-access-point',
            permission: 'Player List',
            route: playerReport('active'),
        },
        {
            title: 'Cash In',
            icon: 'mdi-cash-plus',
            permission: 'Cash In',
            route: cashierIndex('cashin'),
        },
        {
            title: 'Cash Out',
            icon: 'mdi-cash-minus',
            permission: 'Cash Out',
            route: cashierIndex('cashout'),
        },
    ].filter((item) => can(item.permission)),
);
const navigationSections = computed(() =>
    [
        {
            value: 'ending',
            title: 'Ending Game',
            icon: 'mdi-calendar-clock',
            items: [
                {
                    title: 'Scheduled Games',
                    icon: 'mdi-calendar-clock',
                    permission: 'Ending Games',
                    route: EndingGameController.index(),
                },
                {
                    title: 'Teams',
                    icon: 'mdi-account-group',
                    permission: 'Ending Teams',
                    route: EndingCatalogController.teams(),
                },
                {
                    title: 'Leagues',
                    icon: 'mdi-trophy-outline',
                    permission: 'Ending Leagues',
                    route: EndingCatalogController.leagues(),
                },
                {
                    title: 'Config',
                    icon: 'mdi-cog',
                    permission: 'Ending Config',
                    route: EndingCatalogController.configuration(),
                },
            ].filter((item) => can(item.permission)),
        },
        {
            value: 'otso',
            title: 'Otso Panalo Game',
            icon: 'mdi-basketball',
            items: [
                {
                    title: 'Games',
                    icon: 'mdi-basketball',
                    permission: 'Otso Panalo',
                    route: otsoGames(),
                },
                {
                    title: 'Bets',
                    icon: 'mdi-ticket-confirmation-outline',
                    permission: 'Otso Panalo',
                    route: otsoBets(),
                },
                {
                    title: 'Game Configs',
                    icon: 'mdi-cog-outline',
                    permission: 'Otso Panalo Game Types',
                    route: otsoConfigs(),
                },
            ].filter((item) => can(item.permission)),
        },
    ].filter((section) => section.items.length),
);
const playersActive = computed(() =>
    playerNavigation.value.some((item) => item.active ?? isActive(item.route.url)),
);

function isActive(url: string) {
    return currentPath.value === url || currentPath.value.startsWith(`${url}/`);
}

function closeOnMobile() {
    if (mobile.value) {
        drawer.value = false;
    }
}

function expandGroup(group: string) {
    if (!openedGroups.value.includes(group)) {
        openedGroups.value.push(group);
    }

    emit('expand');
}
</script>

<template>
    <v-navigation-drawer
        id="admin-navigation"
        v-model="drawer"
        :rail="rail"
        :width="280"
        :rail-width="72"
        :order="-1"
        :border="0"
        elevation="2"
        aria-label="Main navigation"
    >
        <template #prepend>
            <v-sheet height="104" class="d-flex align-center justify-center px-4">
                <BrandLogo :width="rail ? 40 : 150" :height="rail ? 40 : 61" class="mx-auto" />
                <v-btn
                    v-if="mobile"
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    aria-label="Close sidebar"
                    @click="drawer = false"
                />
            </v-sheet>
        </template>
        <v-list
            v-model:opened="openedGroups"
            class="pa-0"
            color="primary"
            :lines="false"
            open-strategy="multiple"
            :indent="24"
        >
            <v-defaults-provider :defaults="{ VListItem: { minHeight: 48, prependGap: 16 } }">
                <AdminNavigationItem
                    title="Dashboard"
                    icon="mdi-view-dashboard-outline"
                    :href="dashboard.url()"
                    :active="isActive(dashboard.url())"
                    :rail="rail"
                    @click="closeOnMobile"
                />
                <v-list-group v-if="playerNavigation.length" value="players" fluid>
                    <template #activator="{ props, isOpen }">
                        <v-list-item
                            v-bind="props"
                            :active="playersActive"
                            :aria-expanded="!rail && isOpen"
                            aria-label="Players"
                            role="button"
                            :append-icon="rail ? '' : undefined"
                            :prepend-gap="rail ? 0 : 16"
                            :class="rail ? 'px-6' : 'px-4'"
                            @click="rail && expandGroup('players')"
                        >
                            <template #prepend>
                                <v-icon icon="mdi-account-group" color="primary" size="22" />
                            </template>
                            <v-list-item-title v-show="!rail" class="text-body-2">
                                Players
                            </v-list-item-title>
                            <v-tooltip
                                v-if="rail"
                                activator="parent"
                                location="end"
                                text="Players"
                            />
                        </v-list-item>
                    </template>
                    <template v-if="!rail">
                        <AdminNavigationItem
                            v-for="item in playerNavigation"
                            :key="item.title"
                            :title="item.title"
                            :icon="item.icon"
                            :href="item.route.url"
                            :active="item.active ?? isActive(item.route.url)"
                            :rail="rail"
                            @click="closeOnMobile"
                        />
                    </template>
                </v-list-group>
                <v-list-group
                    v-for="section in navigationSections"
                    :key="section.value"
                    :value="section.value"
                    fluid
                >
                    <template #activator="{ props, isOpen }">
                        <v-list-item
                            v-bind="props"
                            :active="section.items.some((item) => isActive(item.route.url))"
                            :aria-expanded="!rail && isOpen"
                            :aria-label="section.title"
                            role="button"
                            :append-icon="rail ? '' : undefined"
                            :prepend-gap="rail ? 0 : 16"
                            :class="rail ? 'px-6' : 'px-4'"
                            @click="rail && expandGroup(section.value)"
                        >
                            <template #prepend>
                                <v-icon :icon="section.icon" color="primary" size="22" />
                            </template>
                            <v-list-item-title v-show="!rail" class="text-body-2">
                                {{ section.title }}
                            </v-list-item-title>
                            <v-tooltip
                                v-if="rail"
                                activator="parent"
                                location="end"
                                :text="section.title"
                            />
                        </v-list-item>
                    </template>
                    <template v-if="!rail">
                        <AdminNavigationItem
                            v-for="item in section.items"
                            :key="item.title"
                            :title="item.title"
                            :icon="item.icon"
                            :href="item.route.url"
                            :active="isActive(item.route.url)"
                            :rail="rail"
                            @click="closeOnMobile"
                        />
                    </template>
                </v-list-group>
                <v-list-group
                    v-if="canAny(['Users', 'Manage users and roles'])"
                    value="settings"
                    fluid
                >
                    <template #activator="{ props, isOpen }">
                        <v-list-item
                            v-bind="props"
                            :active="isActive('/settings')"
                            :aria-expanded="!rail && isOpen"
                            aria-label="Settings"
                            role="button"
                            :append-icon="rail ? '' : undefined"
                            :prepend-gap="rail ? 0 : 16"
                            :class="rail ? 'px-6' : 'px-4'"
                            @click="rail && expandGroup('settings')"
                        >
                            <template #prepend>
                                <v-icon icon="mdi-cog-outline" color="primary" size="22" />
                            </template>
                            <v-list-item-title v-show="!rail" class="text-body-2">
                                Settings
                            </v-list-item-title>
                            <v-tooltip
                                v-if="rail"
                                activator="parent"
                                location="end"
                                text="Settings"
                            />
                        </v-list-item>
                    </template>
                    <AdminNavigationItem
                        v-if="!rail"
                        title="Users"
                        icon="mdi-account-cog-outline"
                        :href="SettingsUserController.index.url()"
                        :active="isActive(SettingsUserController.index.url())"
                        :rail="rail"
                        @click="closeOnMobile"
                    />
                </v-list-group>
            </v-defaults-provider>
        </v-list>
    </v-navigation-drawer>
</template>
