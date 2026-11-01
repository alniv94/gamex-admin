<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SettingsRoleController from '@/actions/App/Http/Controllers/SettingsRoleController';
import type { AdminPermission, ManagedRole } from '@/types/admin-users';
import PermissionPicker from './PermissionPicker.vue';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{
    role: ManagedRole | null;
    permissions: AdminPermission[];
    readonly: boolean;
}>();
const form = useForm({ name: '', permissions: [] as string[], version: 0 });
watch(open, (value) => {
    if (!value) return;
    form.clearErrors();
    form.name = props.role?.name ?? '';
    form.permissions = [...(props.role?.permissions ?? [])];
    form.version = props.role?.version ?? 0;
});
function save() {
    if (props.readonly || form.processing) return;
    form.submit(
        props.role ? SettingsRoleController.update(props.role.id) : SettingsRoleController.store(),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
        },
    );
}
</script>

<template>
    <v-dialog
        v-model="open"
        max-width="800"
        scrollable
        :persistent="form.processing"
        aria-label="Role details"
    >
        <v-card>
            <v-card-title>{{
                readonly ? 'Role access' : role ? 'Edit role' : 'Create role'
            }}</v-card-title>
            <v-divider />
            <v-card-text>
                <form id="admin-role-form" @submit.prevent="save">
                    <v-alert
                        v-if="form.hasErrors"
                        type="error"
                        variant="tonal"
                        class="mb-4"
                        role="alert"
                        ><div v-for="(error, key) in form.errors" :key="key">
                            {{ error }}
                        </div></v-alert
                    >
                    <v-alert v-if="role" type="info" variant="tonal" class="mb-4"
                        >Changes apply to all {{ role.users_count }} users assigned to this
                        role.</v-alert
                    >
                    <v-text-field
                        v-model="form.name"
                        label="Role name"
                        :readonly="readonly"
                        variant="outlined"
                        maxlength="100"
                        autocomplete="off"
                    />
                    <PermissionPicker
                        v-model="form.permissions"
                        :permissions="permissions"
                        :readonly="readonly"
                    />
                </form>
            </v-card-text>
            <v-divider />
            <v-card-actions>
                <v-spacer />
                <v-btn :disabled="form.processing" @click="open = false">{{
                    readonly ? 'Close' : 'Cancel'
                }}</v-btn>
                <v-btn
                    v-if="!readonly"
                    color="primary"
                    variant="flat"
                    type="submit"
                    form="admin-role-form"
                    :loading="form.processing"
                    >{{ role ? 'Save role' : 'Create role' }}</v-btn
                >
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
