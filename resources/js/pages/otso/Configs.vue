<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { storeConfig, updateConfig } from '@/actions/App/Http/Controllers/OtsoPanaloController';
import { usePermissions } from '@/composables/usePermissions';
import OtsoShell from '@/components/otso/OtsoShell.vue';
import EntityLogo from '@/components/ending/EntityLogo.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import { money } from '@/types/ending';
import type { PageResult } from '@/types/ending';
import { prizeFields } from '@/types/otso';
import type { OtsoConfig } from '@/types/otso';

defineProps<{ configs: PageResult<OtsoConfig>; migrationReady: boolean }>();
const { can } = usePermissions();
const page = usePage();
const dialog = ref(false);
const selected = ref<number | null>(null);
const savedLogo = ref<string | null>(null);
const logoPreview = ref<string | null>(null);
const prizeTiers = [
    { key: 'jackpot_prize', label: 'Jackpot Prize', hint: '4 matching quarters' },
    { key: 'second_prize', label: 'Second Prize', hint: '3 matching quarters' },
    { key: 'third_prize', label: 'Third Prize', hint: '2 matching quarters' },
    { key: 'fourth_prize', label: 'Fourth Prize', hint: '1 matching quarter' },
] as const;
const form = useForm({
    name: '',
    logo: null as File | null,
    bet_amount: '',
    jackpot_prize: '',
    second_prize: '',
    third_prize: '',
    fourth_prize: '',
    pool_percentage: '',
    status: 'Inactive',
});

watch(
    () => form.logo,
    (file, _previous, onCleanup) => {
        logoPreview.value = file ? URL.createObjectURL(file) : null;
        if (logoPreview.value) {
            const url = logoPreview.value;
            onCleanup(() => URL.revokeObjectURL(url));
        }
    },
);
watch(dialog, (isOpen) => {
    if (!isOpen) form.logo = null;
});

function open(config?: OtsoConfig) {
    form.reset();
    form.clearErrors();
    selected.value = config?.id ?? null;
    savedLogo.value = config?.logo ?? null;
    if (config) {
        form.name = config.name;
        form.status = config.status;
        for (const field of prizeFields) form[field.key] = config[field.key];
    }
    dialog.value = true;
}
function submit() {
    if (form.processing) return;
    form.post(selected.value ? updateConfig.url(selected.value) : storeConfig.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (dialog.value = false),
    });
}
</script>

<template>
    <OtsoShell title="Game Configurations" :migration-ready="migrationReady">
        <v-sheet class="otso-config-content" color="transparent">
            <p class="mt-n3 mb-6 text-body-medium text-medium-emphasis">
                Manage bet amounts, prize tiers, and settings for new games.
            </p>
            <v-card border>
                <div
                    class="d-flex flex-column flex-sm-row align-sm-center justify-space-between ga-4 pa-4 pa-sm-6"
                >
                    <div class="d-flex align-center ga-3">
                        <v-avatar color="#007eaf" variant="tonal" rounded="lg" size="44">
                            <v-icon icon="mdi-tune-variant" size="22" />
                        </v-avatar>
                        <div>
                            <h2
                                class="d-flex align-center ga-2 text-title-medium font-weight-semibold mb-1"
                            >
                                Game configs
                                <v-chip size="x-small" label variant="tonal" color="secondary">{{
                                    configs.total
                                }}</v-chip>
                            </h2>
                            <p class="text-body-small text-medium-emphasis">
                                Only one configuration can be active at a time.
                            </p>
                        </div>
                    </div>
                    <v-btn
                        v-if="can('Otso Panalo Update Game Types')"
                        :disabled="!migrationReady"
                        color="#007eaf"
                        prepend-icon="mdi-plus"
                        height="40"
                        rounded="lg"
                        class="text-none text-body-small font-weight-medium"
                        @click="open()"
                        >Create Config</v-btn
                    >
                </div>
                <v-divider />
                <v-table class="ending-table" aria-label="Game configurations" hover>
                    <thead class="bg-background text-medium-emphasis">
                        <tr>
                            <th scope="col" class="ps-6">Configuration</th>
                            <th
                                v-for="field in prizeFields"
                                :key="field.key"
                                scope="col"
                                class="text-right"
                            >
                                {{ field.label }}
                            </th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="config in configs.data"
                            :key="config.id"
                            :class="{ 'otso-config-active': config.status === 'Active' }"
                        >
                            <td class="ps-6">
                                <div class="d-flex align-center ga-3 text-no-wrap">
                                    <EntityLogo :src="config.logo" :name="config.name" :size="36" />
                                    <div class="d-flex flex-column ga-1">
                                        <strong class="font-weight-medium">{{
                                            config.name
                                        }}</strong>
                                        <span class="text-body-small text-medium-emphasis">{{
                                            config.status === 'Active'
                                                ? 'Used for new games'
                                                : 'Saved configuration'
                                        }}</span>
                                    </div>
                                </div>
                            </td>
                            <td
                                v-for="field in prizeFields"
                                :key="field.key"
                                class="otso-config-number text-right text-no-wrap"
                            >
                                <span
                                    v-if="field.key !== 'pool_percentage'"
                                    class="text-medium-emphasis me-1"
                                    >₱</span
                                >
                                {{ money(config[field.key])
                                }}{{ field.key === 'pool_percentage' ? '%' : '' }}
                            </td>
                            <td>
                                <v-chip
                                    size="small"
                                    variant="tonal"
                                    :color="config.status === 'Active' ? 'success' : 'secondary'"
                                >
                                    <v-icon
                                        :icon="
                                            config.status === 'Active'
                                                ? 'mdi-check-circle'
                                                : 'mdi-circle-outline'
                                        "
                                        size="12"
                                        start
                                    />
                                    {{ config.status }}
                                </v-chip>
                            </td>
                            <td class="text-right">
                                <v-btn
                                    v-if="can('Otso Panalo Update Game Types')"
                                    :disabled="!migrationReady"
                                    prepend-icon="mdi-pencil-outline"
                                    variant="text"
                                    size="small"
                                    aria-label="Edit configuration"
                                    class="text-none text-body-small"
                                    @click="open(config)"
                                    >Edit</v-btn
                                >
                            </td>
                        </tr>
                        <tr v-if="!configs.data.length">
                            <td colspan="9">
                                <v-sheet class="py-10 px-4 text-center text-medium-emphasis">
                                    <v-icon icon="mdi-tune-variant" size="32" />
                                    <h3 class="text-title-medium mt-3 mb-1">
                                        No configurations yet
                                    </h3>
                                    <p>
                                        Create a configuration to set up bet amounts and prizes for
                                        new games.
                                    </p>
                                </v-sheet>
                            </td>
                        </tr>
                    </tbody>
                </v-table>
                <EndingPager :page="configs" selectable />
            </v-card>
            <p class="d-flex align-start ga-2 mt-4 text-body-small text-medium-emphasis">
                <v-icon icon="mdi-information-outline" size="16" />
                Changes apply to new games. Existing games keep their saved prizes.
            </p>

            <v-dialog
                v-model="dialog"
                max-width="980"
                scrollable
                :persistent="form.processing"
                aria-labelledby="otso-config-title"
                aria-describedby="otso-config-description"
            >
                <v-form :disabled="form.processing" @submit.prevent="submit">
                    <v-card class="otso-config-dialog" rounded="xl">
                        <v-card-item class="pa-4 pa-sm-6 flex-shrink-0 align-start">
                            <template #prepend>
                                <v-avatar color="#007eaf" variant="tonal" rounded="lg" size="40">
                                    <v-icon icon="mdi-tune-variant" size="24" />
                                </v-avatar>
                            </template>
                            <v-card-title
                                id="otso-config-title"
                                class="text-title-medium text-sm-title-large font-weight-semibold text-wrap pa-0"
                            >
                                {{ selected ? 'Edit Game Config' : 'Create Game Config' }}
                            </v-card-title>
                            <p
                                id="otso-config-description"
                                class="text-body-small text-medium-emphasis mt-1"
                            >
                                Set up how players bet and what they can win.
                            </p>
                            <template #append>
                                <v-btn
                                    icon="mdi-close"
                                    variant="text"
                                    size="small"
                                    color="secondary"
                                    aria-label="Close configuration"
                                    :disabled="form.processing"
                                    @click="dialog = false"
                                />
                            </template>
                        </v-card-item>
                        <v-divider />
                        <v-card-text class="otso-config-dialog-body pa-4 pa-sm-6">
                            <v-alert
                                v-if="page.props.errors.action"
                                type="error"
                                variant="tonal"
                                class="mb-4"
                                >{{ page.props.errors.action }}</v-alert
                            >
                            <v-row>
                                <v-col cols="12" md="8">
                                    <section aria-labelledby="otso-config-details">
                                        <div class="d-flex align-center ga-2 mb-4">
                                            <span
                                                class="text-body-small font-weight-semibold text-info"
                                                >01</span
                                            >
                                            <h3
                                                id="otso-config-details"
                                                class="text-title-small font-weight-semibold"
                                            >
                                                Game details
                                            </h3>
                                        </div>
                                        <v-text-field
                                            rounded="lg"
                                            color="#007eaf"
                                            v-model="form.name"
                                            label="Name"
                                            placeholder="e.g. Otso Panalo"
                                            persistent-placeholder
                                            maxlength="255"
                                            :error-messages="form.errors.name"
                                        />
                                        <div class="d-flex align-start ga-3 mt-4">
                                            <v-avatar
                                                size="56"
                                                rounded="lg"
                                                class="border bg-background flex-shrink-0"
                                            >
                                                <v-img
                                                    v-if="logoPreview"
                                                    :src="logoPreview"
                                                    alt="Selected logo preview"
                                                    :cover="false"
                                                />
                                                <EntityLogo
                                                    v-else
                                                    :src="savedLogo"
                                                    :name="form.name || 'Game'"
                                                    :size="48"
                                                />
                                            </v-avatar>
                                            <div class="flex-grow-1 otso-config-logo-field">
                                                <v-file-input
                                                    variant="outlined"
                                                    density="compact"
                                                    hide-details="auto"
                                                    rounded="lg"
                                                    color="#007eaf"
                                                    v-model="form.logo"
                                                    accept="image/png,image/jpeg,image/webp"
                                                    label="Logo (optional)"
                                                    placeholder="Choose an image"
                                                    persistent-placeholder
                                                    prepend-icon=""
                                                    prepend-inner-icon="mdi-image-outline"
                                                    show-size
                                                    :error-messages="form.errors.logo"
                                                />
                                                <p
                                                    class="text-body-small text-medium-emphasis mt-1"
                                                >
                                                    PNG, JPG, or WebP · Up to 2 MB{{
                                                        savedLogo
                                                            ? ' · Leave empty to keep the current logo'
                                                            : ''
                                                    }}
                                                </p>
                                            </div>
                                        </div>
                                    </section>

                                    <v-divider class="my-6" />
                                    <section aria-labelledby="otso-config-betting">
                                        <div class="d-flex align-center ga-2 mb-4">
                                            <span
                                                class="text-body-small font-weight-semibold text-info"
                                                >02</span
                                            >
                                            <h3
                                                id="otso-config-betting"
                                                class="text-title-small font-weight-semibold"
                                            >
                                                Betting settings
                                            </h3>
                                        </div>
                                        <v-row dense>
                                            <v-col cols="12" sm="6">
                                                <v-text-field
                                                    rounded="lg"
                                                    color="#007eaf"
                                                    v-model="form.bet_amount"
                                                    label="Bet Amount"
                                                    type="number"
                                                    inputmode="decimal"
                                                    min="0.01"
                                                    max="99999999.99"
                                                    step="0.01"
                                                    prefix="₱"
                                                    placeholder="0.00"
                                                    persistent-placeholder
                                                    :error-messages="form.errors.bet_amount"
                                                />
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field
                                                    rounded="lg"
                                                    color="#007eaf"
                                                    v-model="form.pool_percentage"
                                                    label="Pool Percentage"
                                                    type="number"
                                                    inputmode="decimal"
                                                    min="0"
                                                    max="100"
                                                    step="0.01"
                                                    suffix="%"
                                                    placeholder="0.00"
                                                    persistent-placeholder
                                                    :error-messages="form.errors.pool_percentage"
                                                />
                                            </v-col>
                                        </v-row>
                                        <p class="text-body-small text-medium-emphasis mt-2">
                                            Pool percentage is retained for records and does not
                                            increase future jackpots.
                                        </p>
                                    </section>

                                    <v-divider class="my-6" />
                                    <section aria-labelledby="otso-config-prizes">
                                        <div class="d-flex align-center ga-2 mb-4">
                                            <span
                                                class="text-body-small font-weight-semibold text-info"
                                                >03</span
                                            >
                                            <h3
                                                id="otso-config-prizes"
                                                class="text-title-small font-weight-semibold"
                                            >
                                                Prize structure
                                            </h3>
                                            <span
                                                class="ms-auto text-body-small text-medium-emphasis"
                                                >PHP</span
                                            >
                                        </div>
                                        <v-row dense>
                                            <v-col
                                                v-for="tier in prizeTiers"
                                                :key="tier.key"
                                                cols="12"
                                                sm="6"
                                            >
                                                <v-text-field
                                                    rounded="lg"
                                                    color="#007eaf"
                                                    v-model="form[tier.key]"
                                                    :label="tier.label"
                                                    type="number"
                                                    inputmode="decimal"
                                                    min="0"
                                                    max="99999999.99"
                                                    step="0.01"
                                                    prefix="₱"
                                                    placeholder="0.00"
                                                    persistent-placeholder
                                                    :error-messages="form.errors[tier.key]"
                                                />
                                                <p
                                                    class="text-body-small text-medium-emphasis mt-1"
                                                >
                                                    {{ tier.hint }}
                                                </p>
                                            </v-col>
                                        </v-row>
                                        <p class="text-body-small text-medium-emphasis mt-2">
                                            Jackpot winners share the configured jackpot. Other
                                            prizes are per winning ticket.
                                        </p>
                                    </section>
                                </v-col>

                                <v-col cols="12" md="4" tag="aside">
                                    <v-row>
                                        <v-col cols="12" sm="6" md="12">
                                            <v-card
                                                tag="section"
                                                border
                                                color="background"
                                                class="pa-4"
                                                aria-labelledby="otso-config-activation"
                                            >
                                                <h3
                                                    id="otso-config-activation"
                                                    class="d-flex align-center ga-2 text-title-small font-weight-semibold mb-4"
                                                >
                                                    <v-icon icon="mdi-power" size="18" /> Activation
                                                </h3>
                                                <v-select
                                                    variant="outlined"
                                                    density="compact"
                                                    hide-details="auto"
                                                    rounded="lg"
                                                    color="#007eaf"
                                                    v-model="form.status"
                                                    :items="['Active', 'Inactive']"
                                                    label="Status"
                                                    bg-color="surface"
                                                    :error-messages="form.errors.status"
                                                />
                                                <p
                                                    class="text-body-small text-medium-emphasis mt-3"
                                                >
                                                    {{
                                                        form.status === 'Active'
                                                            ? 'Use this configuration for new games.'
                                                            : 'Save this configuration without activating it.'
                                                    }}
                                                </p>
                                                <v-alert
                                                    v-if="form.status === 'Active'"
                                                    type="warning"
                                                    variant="tonal"
                                                    density="compact"
                                                    rounded="lg"
                                                    class="mt-4 text-body-small"
                                                    role="status"
                                                    >Saving as active will deactivate any other
                                                    active configuration.</v-alert
                                                >
                                            </v-card>
                                        </v-col>
                                        <v-col cols="12" sm="6" md="12">
                                            <v-card
                                                tag="section"
                                                border
                                                class="pa-4"
                                                aria-labelledby="otso-config-summary-title"
                                                role="region"
                                            >
                                                <h3
                                                    id="otso-config-summary-title"
                                                    class="text-title-small font-weight-semibold mb-4"
                                                >
                                                    At a glance
                                                </h3>
                                                <dl class="otso-config-number">
                                                    <div>
                                                        <dt
                                                            class="text-body-small text-medium-emphasis mb-1"
                                                        >
                                                            Bet amount
                                                        </dt>
                                                        <dd
                                                            class="ma-0 text-title-medium font-weight-medium"
                                                        >
                                                            ₱ {{ money(form.bet_amount || 0) }}
                                                        </dd>
                                                    </div>
                                                    <div class="py-4 my-4 border-t border-b">
                                                        <dt
                                                            class="d-flex align-center ga-1 text-body-small text-medium-emphasis mb-1"
                                                        >
                                                            <v-icon
                                                                icon="mdi-trophy-outline"
                                                                size="16"
                                                            />
                                                            Jackpot
                                                        </dt>
                                                        <dd
                                                            class="ma-0 text-title-large font-weight-semibold text-info text-break"
                                                        >
                                                            ₱ {{ money(form.jackpot_prize || 0) }}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt
                                                            class="text-body-small text-medium-emphasis mb-1"
                                                        >
                                                            Pool percentage
                                                        </dt>
                                                        <dd
                                                            class="ma-0 text-title-medium font-weight-medium"
                                                        >
                                                            {{ money(form.pool_percentage || 0) }}%
                                                        </dd>
                                                    </div>
                                                </dl>
                                            </v-card>
                                        </v-col>
                                        <v-col cols="12">
                                            <v-sheet
                                                color="transparent"
                                                class="d-flex align-start ga-2 text-medium-emphasis"
                                            >
                                                <v-icon icon="mdi-information-outline" size="19" />
                                                <div>
                                                    <h3 class="text-body-small font-weight-medium">
                                                        Fixed jackpot, every game
                                                    </h3>
                                                    <p class="text-body-small mt-1">
                                                        Each new game uses the configured jackpot
                                                        with no rollover. Existing games keep their
                                                        saved prizes.
                                                    </p>
                                                </div>
                                            </v-sheet>
                                        </v-col>
                                    </v-row>
                                </v-col>
                            </v-row>
                        </v-card-text>
                        <v-divider />
                        <v-card-actions
                            class="d-flex flex-column flex-sm-row align-stretch align-sm-center justify-space-between ga-3 pa-4 px-sm-6"
                        >
                            <span
                                class="d-flex align-center ga-2 text-body-small text-medium-emphasis"
                            >
                                <v-icon icon="mdi-content-save-outline" size="16" />
                                {{
                                    selected
                                        ? 'Review your changes before saving.'
                                        : 'You can edit this configuration later.'
                                }}
                            </span>
                            <div class="d-flex ga-2 flex-shrink-0">
                                <v-btn
                                    variant="outlined"
                                    color="secondary"
                                    class="flex-grow-1 flex-sm-grow-0 text-none text-body-small font-weight-medium"
                                    height="40"
                                    rounded="lg"
                                    :disabled="form.processing"
                                    @click="dialog = false"
                                    >Cancel</v-btn
                                >
                                <v-btn
                                    color="#007eaf"
                                    variant="flat"
                                    type="submit"
                                    prepend-icon="mdi-check"
                                    class="flex-grow-1 flex-sm-grow-0 text-none text-body-small font-weight-medium"
                                    height="40"
                                    rounded="lg"
                                    :loading="form.processing"
                                    :disabled="form.processing"
                                    >Save Config</v-btn
                                >
                            </div>
                        </v-card-actions>
                    </v-card>
                </v-form>
            </v-dialog>
        </v-sheet>
    </OtsoShell>
</template>

<style scoped>
.otso-config-content,
.otso-config-dialog {
    --v-font-body: Poppins, sans-serif;
    --v-font-heading: var(--v-font-body);
}

.otso-config-number {
    font-variant-numeric: tabular-nums;
}

.otso-config-active > td:first-child {
    box-shadow: inset 3px 0 rgb(var(--v-theme-success));
}

.otso-config-logo-field {
    min-width: 0;
}

.otso-config-dialog-body {
    overscroll-behavior: contain;
}

.otso-config-dialog :deep(.v-field__input),
.otso-config-dialog :deep(.v-label) {
    font-size: 12px;
}

.otso-config-dialog :deep(.v-label) {
    color: rgb(var(--v-theme-on-surface));
    opacity: 0.7;
}

.otso-config-dialog :deep(.v-field--focused .v-label) {
    color: #007eaf;
    opacity: 1;
}

.otso-config-dialog :deep(.v-field--error .v-label) {
    color: rgb(var(--v-theme-error));
    opacity: 1;
}
</style>
