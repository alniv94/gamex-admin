<script setup lang="ts">
import AdminDataTable from '@/components/AdminDataTable.vue';
import { usePermissions } from '@/composables/usePermissions';
import { verify, reject, setting, fund } from '@/actions/App/Http/Controllers/PlayerController';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PlayerShell from '../../components/players/PlayerShell.vue';
import PlayerStatus from '../../components/players/PlayerStatus.vue';
import NdrpData from '../../components/players/NdrpData.vue';
import PlayerActivity from '../../components/players/PlayerActivity.vue';
import type { PageResult } from '../../types/ending';
import type { PlayerDetails, FundAccount, PlayerActivity as Activity } from '../../types/players';
import { playerDate, playerMoney } from '../../types/players';
const props = defineProps<{
    player: PlayerDetails;
    fundAccounts: FundAccount[];
    rejectReasons: { id: number; reason: string }[];
    activity: PageResult<Activity>;
    filters: Record<string, string>;
}>();
const { can } = usePermissions();
const canVerify = computed(() => can('Player Verify'));
const canSet = computed(() => can('Player Settings'));
const canEvaluate = computed(
    () => canVerify.value && ['Files Submitted', 'Pending'].includes(props.player.account_verified),
);
type Setting = 'betting' | 'cashin' | 'cashout' | 'account';
const settings: { key: Setting; label: string }[] = [
    { key: 'betting', label: 'Betting' },
    { key: 'cashin', label: 'Cash In' },
    { key: 'cashout', label: 'Cash Out' },
    { key: 'account', label: 'Account Status' },
];
const personal = computed(() => [
    ["Player's ID", props.player.id],
    ['Verification Status', props.player.account_verified],
    ['Account Status', props.player.active_status],
    ['Name', props.player.name],
    ['Gender', props.player.gender],
    ['Marital Status', props.player.marital_status],
    ['Mobile Number', props.player.mobile_number],
    ['Email Address', props.player.email_address],
    ['Date of Birth', playerDate(props.player.birthdate, true)],
    ['Age', props.player.age],
    ['Nationality', props.player.nationality],
    ['Gaming Venue', props.player.gaming_venue],
]);
const additional = computed(() => [
    ['Place of Birth', props.player.birthplace],
    ['Source of Income', props.player.source_of_income],
    ['Nature of Work', props.player.nature_of_work],
    ['Nature of Business', props.player.nature_of_business],
    ['Others Income', props.player.others_income],
    ['Registered Date', playerDate(props.player.created_at)],
    ['Admin Deactivate Reason', props.player.admin_deactivate_reason],
    ['Player Deactivate Reason', props.player.player_deactivate_reason],
    ['Rejected Reason', props.player.rejected_reason],
]);
const actionOpen = ref(false);
const action = ref<'verify' | 'reject' | 'setting' | 'fund'>('verify');
const chosenSetting = ref<Setting>('account');
const chosenFund = ref<FundAccount>();
const fundAction = ref<'verify' | 'reject'>('verify');
const form = useForm({
    expected: '',
    enabled: false,
    reason: '',
    reject_reason_id: null as number | null,
});
function current(key: Setting) {
    return key === 'account' ? props.player.active_status : props.player[key] || 'Disabled';
}
function openAction(value: 'verify' | 'reject') {
    form.reset();
    form.clearErrors();
    form.expected = props.player.account_verified;
    action.value = value;
    actionOpen.value = true;
}
function openSetting(key: Setting) {
    form.reset();
    form.clearErrors();
    chosenSetting.value = key;
    form.expected = current(key);
    form.enabled = !['Enabled', 'Active'].includes(current(key));
    action.value = 'setting';
    actionOpen.value = true;
}
function openFund(account: FundAccount, value: 'verify' | 'reject') {
    form.reset();
    form.clearErrors();
    chosenFund.value = account;
    fundAction.value = value;
    action.value = 'fund';
    actionOpen.value = true;
}
const title = computed(() =>
    action.value === 'setting'
        ? 'Confirmation'
        : action.value === 'fund'
          ? fundAction.value === 'verify'
              ? 'Verify'
              : 'Reject'
          : action.value === 'verify'
            ? 'Verify'
            : 'Reject',
);
const reasonRequired = computed(
    () =>
        (action.value === 'setting' && chosenSetting.value === 'account' && !form.enabled) ||
        (action.value === 'fund' && fundAction.value === 'reject'),
);
function submit() {
    if (form.processing) return;
    const options = { preserveScroll: true, onSuccess: () => (actionOpen.value = false) };
    if (action.value === 'verify') form.submit(verify(props.player.id), options);
    else if (action.value === 'reject') form.submit(reject(props.player.id), options);
    else if (action.value === 'setting')
        form.submit(setting({ player: props.player.id, setting: chosenSetting.value }), options);
    else if (chosenFund.value)
        form.submit(
            fund({ player: props.player.id, fund: chosenFund.value.id, action: fundAction.value }),
            options,
        );
}
const images = computed(() => [
    { title: 'Valid ID', url: props.player.valid_id },
    { title: 'Selfie', url: props.player.selfie },
]);
const imageOpen = ref(false);
const selectedImage = ref(0);
const rotation = ref(0);
const zoom = ref(1);
function openImage(index: number) {
    selectedImage.value = index;
    rotation.value = 0;
    zoom.value = 1;
    imageOpen.value = true;
}
</script>

<template>
    <PlayerShell title="Player Details" details>
        <NdrpData :key="player.id" :player-id="player.id" />
        <div class="player-details-grid">
            <div class="player-details-left">
                <v-card class="mb-4">
                    <div class="player-section-title">Personal Information</div>
                    <div class="px-6 pb-6">
                        <dl class="player-information">
                            <div v-for="[label, value] in personal" :key="String(label)">
                                <dt>{{ label }}</dt>
                                <dd :class="label === 'Verification Status' ? 'text-primary' : ''">
                                    {{ value ?? '-' }}
                                </dd>
                            </div>
                            <dt class="player-address-title">Current Address</dt>
                            <div>
                                <dt>Street, House/Lot &amp; Block No, Room/Floor/Unit No</dt>
                                <dd>{{ player.current_address || '-' }}</dd>
                            </div>
                            <div>
                                <dt>Barangay</dt>
                                <dd>{{ player.current_barangay || '-' }}</dd>
                            </div>
                            <div>
                                <dt>City/Municipality</dt>
                                <dd>{{ player.current_city_mun || '-' }}</dd>
                            </div>
                            <dt class="player-address-title">Permanent Address</dt>
                            <div>
                                <dt>Street, House/Lot &amp; Block No, Room/Floor/Unit No</dt>
                                <dd>{{ player.address || '-' }}</dd>
                            </div>
                            <div>
                                <dt>Barangay</dt>
                                <dd>{{ player.permanent_barangay || '-' }}</dd>
                            </div>
                            <div>
                                <dt>City/Municipality</dt>
                                <dd>{{ player.permanent_city_mun || '-' }}</dd>
                            </div>
                            <div v-for="[label, value] in additional" :key="String(label)">
                                <dt>{{ label }}</dt>
                                <dd>{{ value || '-' }}</dd>
                            </div>
                            <div class="mt-5">
                                <dt>Wallet</dt>
                                <dd>{{ playerMoney(player.wallet) }}</dd>
                            </div>
                        </dl>
                        <div v-if="canEvaluate" class="d-flex justify-end ga-2 mt-5">
                            <v-btn color="error" variant="text" @click="openAction('reject')"
                                >Reject</v-btn
                            ><v-btn color="primary" @click="openAction('verify')">Verify</v-btn>
                        </div>
                    </div>
                </v-card>
                <v-card>
                    <div class="player-section-title">Settings</div>
                    <div class="px-6 pb-6">
                        <div v-for="item in settings" :key="item.key" class="player-setting-row">
                            <span class="text-medium-emphasis">{{ item.label }}</span>
                            <v-switch
                                v-if="
                                    item.key === 'account' || player.account_verified === 'Verified'
                                "
                                :model-value="['Enabled', 'Active'].includes(current(item.key))"
                                :disabled="!canSet"
                                :aria-label="`${item.label} for ${player.name}`"
                                color="primary"
                                density="compact"
                                hide-details
                                @update:model-value="openSetting(item.key)"
                            />
                            <span v-else class="text-medium-emphasis">-</span>
                        </div>
                    </div>
                </v-card>
            </div>
            <div class="player-details-right">
                <v-card class="mb-4">
                    <div class="player-section-title">Uploaded Pictures</div>
                    <div class="player-uploaded-pictures px-6 pb-6">
                        <button
                            v-for="(image, index) in images"
                            :key="image.title"
                            class="player-image-card"
                            :disabled="!image.url"
                            :aria-label="`View ${image.title}`"
                            @click="openImage(index)"
                        >
                            <span class="player-image-caption">{{ image.title }}</span>
                            <v-img
                                v-if="image.url"
                                :src="image.url"
                                :alt="image.title"
                                height="100%"
                                contain
                                ><template #error
                                    ><div class="player-image-empty">
                                        <v-icon icon="mdi-image-off-outline" size="28" /><span
                                            >Image unavailable</span
                                        >
                                    </div></template
                                ><template #placeholder
                                    ><v-progress-circular
                                        indeterminate
                                        size="24"
                                        color="primary" /></template
                            ></v-img>
                            <div v-else class="player-image-empty">
                                <v-icon icon="mdi-image-outline" size="28" /><span
                                    >No image uploaded</span
                                >
                            </div>
                        </button>
                    </div>
                </v-card>
                <v-card class="mb-4">
                    <div class="player-section-title">Player Fund Accounts</div>
                    <v-divider />
                    <AdminDataTable
                        v-if="fundAccounts.length"
                        :items="fundAccounts"
                        :headers="[
                            { title: 'Type', key: 'bank' },
                            { title: 'Account Number', key: 'account_number' },
                            { title: 'Status', key: 'is_verified' },
                            { title: 'Action', key: 'action', sortable: false },
                        ]"
                        :items-per-page="5"
                        :items-per-page-options="[5, 10, 25]"
                        class="players-table"
                    >
                        <template #item.account_number="{ item }"
                            >{{
                                item.type === 'E-Wallet' && /^9\d{9}$/.test(item.account_number)
                                    ? '+63'
                                    : ''
                            }}{{ item.account_number }}
                            <v-chip v-if="item.is_default === 'Yes'" size="x-small" class="ml-2"
                                >Default</v-chip
                            ></template
                        >
                        <template #item.is_verified="{ item }"
                            ><PlayerStatus :status="item.is_verified"
                        /></template>
                        <template #item.action="{ item }"
                            ><v-menu
                                ><template #activator="{ props: activator }"
                                    ><v-btn
                                        v-bind="activator"
                                        icon="mdi-dots-horizontal"
                                        variant="text"
                                        size="small"
                                        :disabled="
                                            !canVerify ||
                                            item.is_verified !== 'Pending' ||
                                            item.is_active !== 'Active'
                                        "
                                        :aria-label="`Review fund account ${item.account_number}`" /></template
                                ><v-list density="compact"
                                    ><v-list-item
                                        title="Verify"
                                        @click="openFund(item, 'verify')" /><v-list-item
                                        title="Reject"
                                        class="text-error"
                                        @click="openFund(item, 'reject')" /></v-list></v-menu
                        ></template>
                    </AdminDataTable>
                    <div v-else class="text-center text-medium-emphasis pa-6">
                        No data available
                    </div>
                </v-card>
                <PlayerActivity :player-id="player.id" :activity="activity" :filters="filters" />
            </div>
        </div>
        <v-dialog
            v-model="actionOpen"
            max-width="500"
            :persistent="form.processing"
            class="players-dialog"
        >
            <v-card class="pa-6">
                <h2 class="mb-4">{{ title }}</h2>
                <form @submit.prevent="submit">
                    <p v-if="action === 'verify'" class="text-medium-emphasis mb-5">
                        Do you really want to verify this player?
                    </p>
                    <p v-else-if="action === 'reject'" class="text-medium-emphasis mb-5">
                        You're about to reject this player's account. Confirm that you have checked
                        the validity of the player's valid ID and selfie.
                    </p>
                    <p v-else-if="action === 'setting'" class="text-medium-emphasis mb-5">
                        Do you really want to {{ form.enabled ? 'enable' : 'disable' }}
                        {{
                            settings
                                .find((item) => item.key === chosenSetting)
                                ?.label.toLowerCase()
                        }}?
                    </p>
                    <p v-else class="text-medium-emphasis mb-5">
                        You're about to {{ fundAction }} {{ chosenFund?.account_number }}, please
                        confirm to proceed.
                    </p>
                    <v-autocomplete
                        v-if="action === 'reject'"
                        v-model="form.reject_reason_id"
                        :items="rejectReasons"
                        item-title="reason"
                        item-value="id"
                        label="Reason"
                        variant="outlined"
                        density="compact"
                        :error-messages="form.errors.reject_reason_id"
                        class="mb-4"
                    />
                    <v-text-field
                        v-if="reasonRequired"
                        v-model="form.reason"
                        label="Reason"
                        placeholder="Enter your reason"
                        :counter="action === 'setting' ? 50 : 500"
                        :maxlength="action === 'setting' ? 50 : 500"
                        :error-messages="form.errors.reason"
                        class="mb-4"
                        autofocus
                    />
                    <v-alert
                        v-if="form.hasErrors"
                        type="error"
                        variant="tonal"
                        class="mb-4"
                        role="alert"
                        >{{ Object.values(form.errors)[0] }}</v-alert
                    >
                    <div class="d-flex justify-end ga-2">
                        <v-btn
                            variant="text"
                            :disabled="form.processing"
                            @click="actionOpen = false"
                            >Cancel</v-btn
                        ><v-btn
                            type="submit"
                            :color="title === 'Reject' ? 'error' : 'primary'"
                            :loading="form.processing"
                            >{{
                                action === 'setting' ? 'Yes' : action === 'fund' ? 'Confirm' : title
                            }}</v-btn
                        >
                    </div>
                </form>
            </v-card>
        </v-dialog>
        <v-dialog v-model="imageOpen" fullscreen class="player-image-viewer">
            <v-card color="#171717">
                <v-toolbar color="#222"
                    ><v-toolbar-title>{{ images[selectedImage]?.title }}</v-toolbar-title
                    ><v-btn
                        icon="mdi-magnify-plus-outline"
                        aria-label="Zoom in"
                        @click="zoom = Math.min(zoom + 0.25, 4)" /><v-btn
                        icon="mdi-magnify-minus-outline"
                        aria-label="Zoom out"
                        @click="zoom = Math.max(zoom - 0.25, 0.5)" /><v-btn
                        icon="mdi-rotate-right"
                        aria-label="Rotate image"
                        @click="rotation += 90" /><v-btn
                        icon="mdi-close"
                        aria-label="Close image viewer"
                        @click="imageOpen = false"
                /></v-toolbar>
                <div class="player-image-stage">
                    <img
                        v-if="images[selectedImage]?.url"
                        :src="images[selectedImage]?.url || ''"
                        :alt="images[selectedImage]?.title"
                        :style="{ transform: `scale(${zoom}) rotate(${rotation}deg)` }"
                    />
                </div>
                <div class="d-flex justify-center ga-3 pa-4">
                    <v-btn
                        v-for="(image, index) in images"
                        :key="image.title"
                        :disabled="!image.url"
                        :variant="selectedImage === index ? 'tonal' : 'text'"
                        @click="openImage(index)"
                        >{{ image.title }}</v-btn
                    >
                </div>
            </v-card>
        </v-dialog>
    </PlayerShell>
</template>
