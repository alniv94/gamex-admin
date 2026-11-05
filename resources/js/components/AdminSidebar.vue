<script setup lang="ts">
import EndingCatalogController from '@/actions/App/Http/Controllers/EndingCatalogController';
import {
    index as agentIndex,
    cashins as agentCashins,
} from '@/actions/App/Http/Controllers/AgentController';
import EndingGameController from '@/actions/App/Http/Controllers/EndingGameController';
import { index as endingReport } from '@/actions/App/Http/Controllers/EndingReportController';
import { index as pagcorReport } from '@/actions/App/Http/Controllers/PagcorReportController';
import {
    index as luckyFourGames,
    bets as luckyFourBets,
    settings as luckyFourSettings,
} from '@/actions/App/Http/Controllers/LuckyFourController';
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
import { index as outletIndex } from '@/actions/App/Http/Controllers/SettingsOutletController';
import { index as smsIndex } from '@/actions/App/Http/Controllers/SettingsSmsController';
import { index as investorIndex } from '@/actions/App/Http/Controllers/InvestorController';
import { index as playerReport } from '@/actions/App/Http/Controllers/PlayerReportController';
import { index as promoAnalytics } from '@/actions/App/Http/Controllers/OtsoPromoAnalyticsController';
import { index as summaryReport } from '@/actions/App/Http/Controllers/SummaryReportController';
import { usePermissions } from '@/composables/usePermissions';
import { endingReportIcons, pagcorReportIcons, sidebarIcons } from '@/lib/sidebarIcons';
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
const openedGroups = ref([
    'players',
    'ending',
    'ending-reports',
    'pagcor-reports',
    'otso',
    'lucky-four',
    'investors',
    'agents',
    'settings',
]);
const currentPath = computed(() => page.url.split(/[?#]/)[0]);
const playerNavigation = computed(() =>
    [
        {
            title: 'Player List',
            icon: sidebarIcons.menu,
            permission: 'Player List',
            route: playerList(),
            active: isActive(playerList.url()) || isActive('/player'),
        },
        {
            title: 'Player Withdrawal Accounts',
            icon: sidebarIcons.menu,
            permission: 'Player List',
            route: withdrawalAccounts(),
        },
        {
            title: 'Registered Player List',
            icon: sidebarIcons.players,
            permission: 'Player List',
            route: playerReport('registered'),
        },
        {
            title: 'Newly Registered Players',
            icon: sidebarIcons.addPlayer,
            permission: 'Player List',
            route: playerReport('new'),
        },
        {
            title: 'Active Players Summary',
            icon: sidebarIcons.activePlayers,
            permission: 'Player List',
            route: playerReport('active'),
        },
        {
            title: 'Cash In',
            icon: sidebarIcons.cashIn,
            permission: 'Cash In',
            route: cashierIndex('cashin'),
        },
        {
            title: 'Cash Out',
            icon: sidebarIcons.cashOut,
            permission: 'Cash Out',
            route: cashierIndex('cashout'),
        },
    ].filter((item) => can(item.permission)),
);
const navigationSections = computed(() =>
    [
        {
            value: 'investors',
            title: 'Investors',
            icon: sidebarIcons.investor,
            items: [
                {
                    title: 'Investor List',
                    icon: sidebarIcons.investor,
                    permission: 'Investors',
                    route: investorIndex('list'),
                },
                {
                    title: 'Payouts Settlement',
                    icon: sidebarIcons.settlement,
                    permission: 'Investors',
                    route: investorIndex('payouts'),
                },
                {
                    title: 'Commission Settlement',
                    icon: sidebarIcons.settlement,
                    permission: 'Investors',
                    route: investorIndex('commissions'),
                },
                {
                    title: 'Master Agent List',
                    icon: sidebarIcons.menu,
                    permission: 'Investors',
                    route: investorIndex('master-agents'),
                },
                {
                    title: 'Special Commission Settlement',
                    icon: sidebarIcons.commission,
                    permission: 'Special Commission Settlement',
                    route: investorIndex('special-commissions'),
                },
                {
                    title: 'Referral Links',
                    icon: sidebarIcons.referral,
                    permission: 'Investors',
                    route: investorIndex('referral-links'),
                },
            ].filter((item) => can(item.permission)),
        },
        {
            value: 'agents',
            title: 'Agents',
            icon: sidebarIcons.players,
            items: [
                {
                    title: 'Agent List',
                    icon: sidebarIcons.menu,
                    permission: 'Ending Agents',
                    route: agentIndex(),
                },
                {
                    title: 'Cash In',
                    icon: sidebarIcons.cashIn,
                    permission: 'Ending Agents',
                    route: agentCashins(),
                },
            ].filter((item) => can(item.permission)),
        },
        {
            value: 'ending',
            title: 'Ending Game',
            icon: sidebarIcons.schedule,
            items: [
                {
                    title: 'Scheduled Games',
                    icon: sidebarIcons.schedule,
                    permission: 'Ending Games',
                    route: EndingGameController.index(),
                },
                {
                    title: 'Teams',
                    icon: sidebarIcons.teams,
                    permission: 'Ending Teams',
                    route: EndingCatalogController.teams(),
                },
                {
                    title: 'Leagues',
                    icon: sidebarIcons.trophy,
                    permission: 'Ending Leagues',
                    route: EndingCatalogController.leagues(),
                },
                {
                    title: 'Config',
                    icon: sidebarIcons.settings,
                    permission: 'Ending Config',
                    route: EndingCatalogController.configuration(),
                },
            ].filter((item) => can(item.permission)),
        },
        {
            value: 'ending-reports',
            title: 'Ending Reports',
            icon: sidebarIcons.analytics,
            items: (page.props.endingReportNavigation ?? [])
                .map((item) => ({
                    ...item,
                    icon: endingReportIcons[item.report] ?? sidebarIcons.documents,
                    route: endingReport(item.report),
                }))
                .filter((item) => can(item.permission)),
        },
        {
            value: 'otso',
            title: 'Otso Panalo Game',
            icon: sidebarIcons.basketball,
            items: [
                {
                    title: 'Promo Analytics',
                    icon: sidebarIcons.analytics,
                    permission: 'Otso Panalo',
                    route: promoAnalytics(),
                },
                {
                    title: 'Games',
                    icon: sidebarIcons.schedule,
                    permission: 'Otso Panalo',
                    route: otsoGames(),
                },
                {
                    title: 'Bets',
                    icon: sidebarIcons.bets,
                    permission: 'Otso Panalo',
                    route: otsoBets(),
                },
                {
                    title: 'Game Configs',
                    icon: sidebarIcons.settings,
                    permission: 'Otso Panalo Game Types',
                    route: otsoConfigs(),
                },
            ].filter((item) => can(item.permission)),
        },
        {
            value: 'lucky-four',
            title: 'Lucky Four',
            icon: sidebarIcons.trophy,
            items: [
                {
                    title: 'Games',
                    icon: sidebarIcons.schedule,
                    permission: 'Lucky Four',
                    route: luckyFourGames(),
                },
                {
                    title: 'Bets',
                    icon: sidebarIcons.bets,
                    permission: 'Lucky Four',
                    route: luckyFourBets(),
                },
                {
                    title: 'Prize Settings',
                    icon: sidebarIcons.settings,
                    permission: 'Lucky Four Prizes Settings',
                    route: luckyFourSettings(),
                },
            ].filter((item) => can(item.permission)),
        },
        {
            value: 'pagcor-reports',
            title: 'PAGCOR Reports',
            icon: sidebarIcons.documents,
            items: (page.props.pagcorReportNavigation ?? [])
                .map((item) => ({
                    ...item,
                    icon: pagcorReportIcons[item.report] ?? sidebarIcons.documents,
                    route: pagcorReport(item.report),
                }))
                .filter((item) => can(item.permission)),
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
                    :icon="sidebarIcons.analytics"
                    :href="dashboard.url()"
                    :active="isActive(dashboard.url())"
                    :rail="rail"
                    @click="closeOnMobile"
                />
                <AdminNavigationItem
                    v-if="can('Players Summary')"
                    title="Summary Report"
                    :icon="sidebarIcons.menu"
                    :href="summaryReport.url()"
                    :active="isActive(summaryReport.url())"
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
                                <img
                                    :src="sidebarIcons.players"
                                    width="24"
                                    height="24"
                                    alt=""
                                    aria-hidden="true"
                                    class="d-block flex-shrink-0"
                                />
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
                                <img
                                    :src="section.icon"
                                    width="24"
                                    height="24"
                                    alt=""
                                    aria-hidden="true"
                                    class="d-block flex-shrink-0"
                                />
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
                    v-if="canAny(['Users', 'Manage users and roles', 'Outlets', 'SMS'])"
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
                                <img
                                    :src="sidebarIcons.settings"
                                    width="24"
                                    height="24"
                                    alt=""
                                    aria-hidden="true"
                                    class="d-block flex-shrink-0"
                                />
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
                        v-if="!rail && canAny(['Users', 'Manage users and roles'])"
                        title="Users"
                        :icon="sidebarIcons.users"
                        :href="SettingsUserController.index.url()"
                        :active="isActive(SettingsUserController.index.url())"
                        :rail="rail"
                        @click="closeOnMobile"
                    />
                    <AdminNavigationItem
                        v-if="!rail && can('Outlets')"
                        title="Outlets"
                        :icon="sidebarIcons.outlet"
                        :href="outletIndex.url()"
                        :active="isActive(outletIndex.url())"
                        :rail="rail"
                        @click="closeOnMobile"
                    />
                    <AdminNavigationItem
                        v-if="!rail && can('SMS')"
                        title="SMS"
                        :icon="sidebarIcons.sms"
                        :href="smsIndex.url()"
                        :active="isActive(smsIndex.url())"
                        :rail="rail"
                        @click="closeOnMobile"
                    />
                </v-list-group>
            </v-defaults-provider>
        </v-list>
    </v-navigation-drawer>
</template>
