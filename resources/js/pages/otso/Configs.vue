<script setup lang="ts">
import { ref } from 'vue';
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
function open(config?: OtsoConfig) {
    form.reset();
    form.clearErrors();
    selected.value = config?.id ?? null;
    if (config) {
        form.name = config.name;
        form.status = config.status;
        for (const field of prizeFields) form[field.key] = config[field.key];
    }
    dialog.value = true;
}
function submit() {
    form.post(selected.value ? updateConfig.url(selected.value) : storeConfig.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (dialog.value = false),
    });
}
</script>
<template>
    <OtsoShell title="Game Configurations" :migration-ready="migrationReady">
        <v-card>
            <div class="ending-toolbar d-flex align-center pa-4">
                <h2>Game Configs</h2>
                <v-spacer /><v-btn
                    v-if="can('Otso Panalo Update Game Types')"
                    :disabled="!migrationReady"
                    color="primary"
                    prepend-icon="mdi-plus"
                    @click="open()"
                    >Create Config</v-btn
                >
            </div>
            <v-table class="ending-table"
                ><thead>
                    <tr>
                        <th>Name</th>
                        <th v-for="field in prizeFields" :key="field.key">{{ field.label }}</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="config in configs.data" :key="config.id">
                        <td>
                            <div class="d-flex align-center ga-2">
                                <EntityLogo :src="config.logo" :name="config.name" />{{
                                    config.name
                                }}
                            </div>
                        </td>
                        <td v-for="field in prizeFields" :key="field.key">
                            {{ money(config[field.key])
                            }}{{ field.key === 'pool_percentage' ? '%' : '' }}
                        </td>
                        <td>
                            <v-chip
                                size="small"
                                :color="config.status === 'Active' ? 'success' : 'secondary'"
                                >{{ config.status }}</v-chip
                            >
                        </td>
                        <td>
                            <v-btn
                                v-if="can('Otso Panalo Update Game Types')"
                                :disabled="!migrationReady"
                                icon="mdi-pencil"
                                variant="text"
                                size="small"
                                aria-label="Edit configuration"
                                @click="open(config)"
                            />
                        </td>
                    </tr>
                    <tr v-if="!configs.data.length">
                        <td colspan="9" class="text-center pa-6">No game configurations found.</td>
                    </tr>
                </tbody></v-table
            ><EndingPager :page="configs" selectable />
        </v-card>
        <v-dialog v-model="dialog" max-width="760" :persistent="form.processing"
            ><v-card class="ending-dialog"
                ><v-card-title>{{
                    selected ? 'Edit Game Config' : 'Create Game Config'
                }}</v-card-title
                ><v-divider />
                <v-form @submit.prevent="submit"
                    ><v-card-text>
                        <v-alert v-if="page.props.errors.action" type="error" class="mb-4">{{
                            page.props.errors.action
                        }}</v-alert>
                        <v-text-field
                            v-model="form.name"
                            label="Name"
                            :error-messages="form.errors.name"
                        />
                        <v-file-input
                            v-model="form.logo"
                            accept="image/png,image/jpeg,image/webp"
                            label="Logo"
                            :error-messages="form.errors.logo"
                        />
                        <v-row
                            ><v-col v-for="field in prizeFields" :key="field.key" cols="12" sm="6"
                                ><v-text-field
                                    v-model="form[field.key]"
                                    :label="field.label"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :max="field.key === 'pool_percentage' ? 100 : undefined"
                                    :error-messages="form.errors[field.key]" /></v-col
                        ></v-row>
                        <v-select
                            v-model="form.status"
                            :items="['Active', 'Inactive']"
                            label="Status"
                            :error-messages="form.errors.status"
                        />
                        <p class="text-caption">
                            Activating this configuration deactivates the previous one. Existing
                            game prizes retain their saved values. Each new game uses the configured
                            jackpot with no rollover.
                        </p> </v-card-text
                    ><v-card-actions class="ending-dialog-actions"
                        ><v-btn :disabled="form.processing" @click="dialog = false">Cancel</v-btn
                        ><v-btn color="primary" type="submit" :loading="form.processing"
                            >Save Config</v-btn
                        ></v-card-actions
                    ></v-form
                >
            </v-card></v-dialog
        >
    </OtsoShell>
</template>
