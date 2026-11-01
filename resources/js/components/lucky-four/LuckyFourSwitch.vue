<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { show, toggle } from '@/actions/App/Http/Controllers/LuckyFourController';
import { usePermissions } from '@/composables/usePermissions';
import type { AdminPageProps } from '@/types';
import type { EndingGame } from '@/types/ending';
const props = defineProps<{ game: EndingGame; ready: boolean; scheduled: boolean }>();
const page = usePage<AdminPageProps>();
const { can } = usePermissions();
const dialog = ref(false);
const form = useForm({ enabled: false });
function open() {
    form.enabled = props.game.is_lucky_four !== 'Yes';
    form.clearErrors();
    dialog.value = true;
}
function save() {
    form.submit(toggle(props.game.id), {
        preserveScroll: true,
        headers: { 'X-CSRF-TOKEN': page.props.csrf_token },
        onSuccess: () => {
            dialog.value = false;
        },
    });
}
</script>
<template>
    <div class="lucky-four-control">
        <v-btn
            v-if="game.is_lucky_four === 'Yes' && can('Lucky Four')"
            variant="text"
            color="primary"
            size="small"
            append-icon="mdi-arrow-top-right"
            @click="router.visit(show(game.id))"
            >Open Lucky Four</v-btn
        >
        <v-switch
            class="lucky-four-toggle"
            :model-value="game.is_lucky_four === 'Yes'"
            :label="game.is_lucky_four === 'Yes' ? 'Enabled' : 'Disabled'"
            aria-labelledby="lucky-four-heading"
            color="primary"
            density="compact"
            hide-details
            :disabled="!can('Lucky Four Enable Disable') || !ready || !scheduled || form.processing"
            @update:model-value="open"
        />
        <v-dialog v-model="dialog" max-width="440" :persistent="form.processing">
            <v-card>
                <v-card-title>{{ form.enabled ? 'Enable' : 'Disable' }} Lucky Four</v-card-title>
                <v-card-text>
                    <p>
                        {{
                            form.enabled
                                ? 'The current prize settings will be saved for this game.'
                                : 'Lucky Four will no longer be available for this game.'
                        }}
                    </p>
                    <v-alert v-if="form.hasErrors" type="error" variant="tonal" class="mt-3">{{
                        Object.values(form.errors).join(' ')
                    }}</v-alert>
                </v-card-text>
                <v-card-actions
                    ><v-spacer /><v-btn :disabled="form.processing" @click="dialog = false"
                        >Cancel</v-btn
                    ><v-btn color="primary" :loading="form.processing" @click="save"
                        >Confirm</v-btn
                    ></v-card-actions
                >
            </v-card>
        </v-dialog>
    </div>
</template>
<style scoped>
.lucky-four-control {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 8px 16px;
}
.lucky-four-toggle {
    flex: 0 0 auto;
}
.lucky-four-toggle :deep(.v-label) {
    min-width: 58px;
    font-size: 12px;
    opacity: 1;
}
@media (max-width: 599px) {
    .lucky-four-control {
        justify-content: flex-start;
    }
}
</style>
