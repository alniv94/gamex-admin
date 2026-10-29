<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import EndingCatalogController from '@/actions/App/Http/Controllers/EndingCatalogController';
import EndingShell from '../../components/ending/EndingShell.vue';
import EndingToolbar from '../../components/ending/EndingToolbar.vue';
import type { AdminPageProps } from '../../types';
import type { ConfigRecord, CardPreset } from '../../types/ending';
import { betType, money } from '../../types/ending';

const props = defineProps<{
    records: ConfigRecord[];
    configNames: string[];
    presets: CardPreset[];
    migrationReady: boolean;
}>();
const page = usePage<AdminPageProps>();
const search = ref('');
const presetSearch = ref('');
const selectedType = ref(2);
const filteredPresets = computed(() =>
    props.presets.filter((p) => p.bet_type_id === selectedType.value),
);
const activeCardTotal = computed(() =>
    filteredPresets.value
        .filter((p) => p.status === 'Active')
        .reduce((total, p) => total + Number(p.card_quantity), 0),
);
const availableNames = computed(() =>
    props.configNames.filter(
        (name) => !props.records.some((record) => record.config_name === name),
    ),
);
const configDialog = ref(false);
const presetDialog = ref(false);
const deleteDialog = ref(false);
const selectedConfig = ref<ConfigRecord | null>(null);
const selectedPreset = ref<CardPreset | null>(null);
const configForm = useForm({ config_name: '', config_desc: '', config_value: '' });
const presetForm = useForm({ bet_type_id: 2, amount: '', card_quantity: 1, status: 'Active' });
const deleteForm = useForm({});
const configHeaders = [
    { title: 'Actions', key: 'actions', sortable: false, width: 90 },
    { title: 'Name', key: 'config_name' },
    { title: 'Description', key: 'config_desc' },
    { title: 'Value', key: 'config_value' },
];
const presetHeaders = [
    { title: 'Actions', key: 'actions', sortable: false, width: 100 },
    { title: 'Amount', key: 'amount' },
    { title: 'Card quantity', key: 'card_quantity' },
    { title: 'Status', key: 'status' },
];
function openConfig(record: ConfigRecord | null) {
    selectedConfig.value = record;
    configForm.defaults({
        config_name: record?.config_name || availableNames.value[0] || '',
        config_desc: record?.config_desc || '',
        config_value: record?.config_value || '',
    });
    configForm.resetAndClearErrors();
    configDialog.value = true;
}
function saveConfig() {
    if (configForm.processing) return;
    configForm.submit(
        selectedConfig.value
            ? EndingCatalogController.updateConfig(selectedConfig.value.id)
            : EndingCatalogController.storeConfig(),
        {
            preserveScroll: true,
            headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
            onSuccess: () => {
                configDialog.value = false;
            },
        },
    );
}
function openPreset(preset: CardPreset | null) {
    selectedPreset.value = preset;
    presetForm.defaults({
        bet_type_id: preset?.bet_type_id || selectedType.value,
        amount: preset?.amount || '',
        card_quantity: preset?.card_quantity ?? 1,
        status: preset?.status || 'Active',
    });
    presetForm.resetAndClearErrors();
    presetDialog.value = true;
}
function savePreset() {
    if (presetForm.processing) return;
    presetForm.submit(
        selectedPreset.value
            ? EndingCatalogController.updatePreset({
                  type: selectedPreset.value.bet_type_id,
                  preset: selectedPreset.value.id,
              })
            : EndingCatalogController.storePreset(),
        {
            preserveScroll: true,
            headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
            onSuccess: () => {
                selectedType.value = presetForm.bet_type_id;
                presetDialog.value = false;
            },
        },
    );
}
function openDelete(preset: CardPreset) {
    selectedPreset.value = preset;
    deleteForm.clearErrors();
    deleteDialog.value = true;
}
function deletePreset() {
    if (!selectedPreset.value || deleteForm.processing) return;
    deleteForm.submit(
        EndingCatalogController.deletePreset({
            type: selectedPreset.value.bet_type_id,
            preset: selectedPreset.value.id,
        }),
        {
            preserveScroll: true,
            headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
            onSuccess: () => {
                deleteDialog.value = false;
            },
        },
    );
}
</script>

<template>
    <EndingShell title="Config" :migration-ready="migrationReady">
        <v-card class="mb-4">
            <EndingToolbar
                v-model="search"
                :create-label="availableNames.length ? 'Create Configuration' : undefined"
                :disabled="!migrationReady"
                @create="openConfig(null)"
                @refresh="router.reload()"
            />
            <v-divider />
            <v-data-table
                :headers="configHeaders"
                :items="records"
                :search="search"
                :items-per-page="5"
                :items-per-page-options="[5, 10, 25, 50]"
                class="ending-table"
                density="compact"
                no-data-text="No configuration found."
            >
                <template #item.actions="{ item }"
                    ><v-btn
                        icon="mdi-cog-outline"
                        color="primary"
                        variant="text"
                        size="x-small"
                        :aria-label="`Edit ${item.config_name}`"
                        :disabled="!migrationReady"
                        @click="openConfig(item)"
                /></template>
            </v-data-table>
        </v-card>
        <v-card>
            <div class="ending-toolbar">
                <div class="d-flex align-center flex-wrap ga-2">
                    <h2 class="text-subtitle-1 font-weight-bold">Bet &amp; Card Presets</h2>
                    <v-btn
                        color="primary"
                        size="small"
                        prepend-icon="mdi-plus"
                        :disabled="!migrationReady"
                        @click="openPreset(null)"
                        >New Preset</v-btn
                    >
                    <v-btn
                        icon="mdi-sync"
                        variant="text"
                        size="small"
                        aria-label="Refresh presets"
                        @click="router.reload({ only: ['presets'] })"
                    />
                </div>
                <v-text-field
                    v-model="presetSearch"
                    label="Search presets"
                    prepend-inner-icon="mdi-magnify"
                    hide-details
                    class="ending-search"
                    clearable
                />
            </div>
            <v-divider />
            <div class="pa-4 pb-0">
                <v-alert type="info" variant="outlined" density="compact" class="mb-3"
                    >Active presets with a card quantity greater than zero are created automatically
                    for every new ending game.</v-alert
                >
                <div class="d-flex align-center flex-wrap ga-3 mb-3">
                    <v-btn-toggle
                        v-model="selectedType"
                        mandatory
                        color="primary"
                        density="compact"
                        variant="outlined"
                        divided
                        aria-label="Preset bet type"
                        ><v-btn :value="2" size="small">Per Quarter</v-btn
                        ><v-btn :value="1" size="small">Traditional</v-btn></v-btn-toggle
                    >
                    <v-chip color="primary" variant="outlined" size="small"
                        >{{ activeCardTotal }} cards per new game</v-chip
                    >
                </div>
            </div>
            <v-data-table
                :headers="presetHeaders"
                :items="filteredPresets"
                :search="presetSearch"
                :items-per-page="25"
                :items-per-page-options="[5, 10, 25, 50]"
                :sort-by="[{ key: 'amount', order: 'asc' }]"
                class="ending-table"
                density="compact"
                no-data-text="No presets found for this bet type."
            >
                <template #item.actions="{ item }">
                    <v-btn
                        icon="mdi-pencil-outline"
                        color="primary"
                        variant="text"
                        size="x-small"
                        :aria-label="`Edit ${betType(item.bet_type_id)} ${money(item.amount)} preset`"
                        :disabled="!migrationReady"
                        @click="openPreset(item)"
                    />
                    <v-btn
                        icon="mdi-trash-can-outline"
                        color="error"
                        variant="text"
                        size="x-small"
                        :aria-label="`Delete ${betType(item.bet_type_id)} ${money(item.amount)} preset`"
                        :disabled="!migrationReady"
                        @click="openDelete(item)"
                    />
                </template>
                <template #item.amount="{ item }">{{ money(item.amount) }}</template>
                <template #item.status="{ item }"
                    ><v-chip
                        :color="item.status === 'Active' ? 'success' : 'default'"
                        size="x-small"
                        >{{ item.status }}</v-chip
                    ></template
                >
            </v-data-table>
        </v-card>
        <v-dialog
            v-model="configDialog"
            max-width="650"
            :persistent="configForm.processing"
            class="ending-dialog"
        >
            <v-card
                ><v-card-title>{{
                    selectedConfig ? 'Edit Configuration' : 'Create Configuration'
                }}</v-card-title
                ><v-divider /><v-card-text>
                    <v-alert
                        v-if="configForm.hasErrors"
                        role="alert"
                        type="error"
                        variant="tonal"
                        class="mb-4"
                        >{{ Object.values(configForm.errors).join(' ') }}</v-alert
                    >
                    <v-form
                        id="ending-config-form"
                        :disabled="configForm.processing"
                        @submit.prevent="saveConfig"
                    >
                        <v-text-field
                            v-if="selectedConfig"
                            :model-value="configForm.config_name"
                            label="Name"
                            readonly
                            class="mb-4"
                        />
                        <v-select
                            v-else
                            v-model="configForm.config_name"
                            :items="availableNames"
                            label="Name"
                            variant="outlined"
                            density="compact"
                            class="mb-4"
                            :error-messages="configForm.errors.config_name"
                        />
                        <v-text-field
                            v-model="configForm.config_desc"
                            label="Description"
                            class="mb-4"
                            :error-messages="configForm.errors.config_desc"
                        />
                        <v-text-field
                            v-model="configForm.config_value"
                            label="Value"
                            type="number"
                            min="0"
                            step="0.01"
                            :error-messages="configForm.errors.config_value"
                        />
                        <p
                            v-if="configForm.config_name.includes('Prize Percent')"
                            class="text-caption text-medium-emphasis mt-3"
                        >
                            This value is the prize multiplier applied to the bet amount when new
                            cards are created.
                        </p>
                    </v-form> </v-card-text
                ><v-card-actions class="pa-4"
                    ><v-spacer /><v-btn
                        :disabled="configForm.processing"
                        @click="configDialog = false"
                        >Cancel</v-btn
                    ><v-btn
                        color="primary"
                        variant="flat"
                        type="submit"
                        form="ending-config-form"
                        :loading="configForm.processing"
                        >Save Configuration</v-btn
                    ></v-card-actions
                ></v-card
            >
        </v-dialog>
        <v-dialog
            v-model="presetDialog"
            max-width="600"
            :persistent="presetForm.processing"
            class="ending-dialog"
        >
            <v-card
                ><v-card-title>{{ selectedPreset ? 'Edit Preset' : 'New Preset' }}</v-card-title
                ><v-divider /><v-card-text>
                    <v-alert
                        v-if="presetForm.hasErrors"
                        role="alert"
                        type="error"
                        variant="tonal"
                        class="mb-4"
                        >{{ Object.values(presetForm.errors).join(' ') }}</v-alert
                    >
                    <v-form
                        id="ending-preset-form"
                        :disabled="presetForm.processing"
                        @submit.prevent="savePreset"
                    >
                        <v-select
                            v-model="presetForm.bet_type_id"
                            :items="[
                                { title: 'Traditional', value: 1 },
                                { title: 'Per Quarter', value: 2 },
                            ]"
                            label="Bet type"
                            :disabled="!!selectedPreset"
                            variant="outlined"
                            density="compact"
                            class="mb-4"
                        />
                        <v-text-field
                            v-model="presetForm.amount"
                            label="Amount"
                            type="number"
                            min="0.01"
                            step="0.01"
                            class="mb-4"
                            :error-messages="presetForm.errors.amount"
                        />
                        <v-text-field
                            v-model.number="presetForm.card_quantity"
                            label="Card quantity"
                            type="number"
                            min="0"
                            max="1000"
                            step="1"
                            class="mb-4"
                            :error-messages="presetForm.errors.card_quantity"
                        />
                        <v-select
                            v-model="presetForm.status"
                            :items="['Active', 'Inactive']"
                            label="Status"
                            variant="outlined"
                            density="compact"
                            :error-messages="presetForm.errors.status"
                        />
                        <p class="text-caption text-medium-emphasis mt-3">
                            Use a quantity of 0 to keep this amount available without automatically
                            creating cards.
                        </p>
                    </v-form> </v-card-text
                ><v-card-actions class="pa-4"
                    ><v-spacer /><v-btn
                        :disabled="presetForm.processing"
                        @click="presetDialog = false"
                        >Cancel</v-btn
                    ><v-btn
                        color="primary"
                        variant="flat"
                        type="submit"
                        form="ending-preset-form"
                        :loading="presetForm.processing"
                        >Save Preset</v-btn
                    ></v-card-actions
                ></v-card
            >
        </v-dialog>
        <v-dialog
            v-model="deleteDialog"
            max-width="440"
            :persistent="deleteForm.processing"
            class="ending-dialog"
        >
            <v-card
                ><v-card-title>Delete Preset?</v-card-title
                ><v-card-text>
                    <p v-if="selectedPreset">
                        Remove the {{ money(selectedPreset.amount) }}
                        {{ betType(selectedPreset.bet_type_id) }} preset from future game creation?
                        Existing games and cards keep their saved amounts and prizes.
                    </p>
                    <v-alert
                        v-if="deleteForm.hasErrors"
                        role="alert"
                        type="error"
                        variant="tonal"
                        class="mt-4"
                        >{{ Object.values(deleteForm.errors).join(' ') }}</v-alert
                    > </v-card-text
                ><v-card-actions class="pa-4"
                    ><v-spacer /><v-btn
                        :disabled="deleteForm.processing"
                        @click="deleteDialog = false"
                        >Cancel</v-btn
                    ><v-btn
                        color="error"
                        variant="flat"
                        :loading="deleteForm.processing"
                        @click="deletePreset"
                        >Delete Preset</v-btn
                    ></v-card-actions
                ></v-card
            >
        </v-dialog>
    </EndingShell>
</template>
