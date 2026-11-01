<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SettingsUserController from '@/actions/App/Http/Controllers/SettingsUserController';
import type { AdminPermission, ManagedRole, ManagedUser } from '@/types/admin-users';
import PermissionPicker from './PermissionPicker.vue';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{
    user: ManagedUser | null;
    roles: ManagedRole[];
    permissions: AdminPermission[];
    readonly: boolean;
}>();
const form = useForm({
    name: '',
    mobile_number: '',
    email: '',
    roles: [] as number[],
    permissions: [] as string[],
    version: 0,
});
watch(open, (value) => {
    if (!value) return;
    form.clearErrors();
    form.name = props.user?.name ?? '';
    form.mobile_number = props.user?.mobile_number ?? '';
    form.email = props.user?.email ?? '';
    form.roles = [...(props.user?.roles ?? [])];
    form.permissions = [...(props.user?.direct_permissions ?? [])];
    form.version = props.user?.version ?? 0;
});
const inherited = computed(() => {
    const sources: Record<string, string[]> = {};
    for (const role of props.roles.filter((role) => form.roles.includes(role.id))) {
        for (const permission of role.permissions) (sources[permission] ??= []).push(role.name);
    }
    return sources;
});
function save() {
    if (props.readonly || form.processing || !props.user) return;
    form.submit(SettingsUserController.update(props.user.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <v-dialog
        v-model="open"
        max-width="900"
        scrollable
        :persistent="form.processing"
        aria-label="User details"
    >
        <v-card>
            <v-card-title>{{ readonly ? 'User access' : 'Edit user' }}</v-card-title>
            <v-divider />
            <v-card-text>
                <form id="admin-user-form" @submit.prevent="save">
                    <v-alert
                        v-if="form.hasErrors"
                        type="error"
                        variant="tonal"
                        class="mb-4"
                        role="alert"
                    >
                        <div v-for="(error, key) in form.errors" :key="key">{{ error }}</div>
                    </v-alert>
                    <v-text-field
                        v-model="form.name"
                        label="Name"
                        :readonly="readonly"
                        variant="outlined"
                        maxlength="255"
                        autocomplete="off"
                    />
                    <v-row class="my-2">
                        <v-col cols="12" sm="6"
                            ><v-text-field
                                v-model="form.mobile_number"
                                label="Mobile number"
                                :readonly="readonly"
                                variant="outlined"
                                placeholder="+639171234567"
                                hint="Used for OTP sign-in"
                                persistent-hint
                                autocomplete="off"
                        /></v-col>
                        <v-col cols="12" sm="6"
                            ><v-text-field
                                v-model="form.email"
                                label="Email (optional)"
                                :readonly="readonly"
                                variant="outlined"
                                type="email"
                                autocomplete="off"
                        /></v-col>
                    </v-row>
                    <v-autocomplete
                        v-model="form.roles"
                        :items="roles"
                        item-title="name"
                        item-value="id"
                        label="Roles"
                        :readonly="readonly"
                        multiple
                        chips
                        closable-chips
                        variant="outlined"
                        hint="Permissions from every selected role are combined."
                        persistent-hint
                        class="mb-4"
                    />
                    <v-alert
                        v-if="user?.status === 'Inactive'"
                        type="info"
                        variant="tonal"
                        class="mb-4"
                        >This user is inactive. Assigned permissions take effect when the account is
                        activated.</v-alert
                    >
                    <PermissionPicker
                        v-model="form.permissions"
                        :permissions="permissions"
                        :inherited="inherited"
                        :readonly="readonly"
                        direct
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
                    form="admin-user-form"
                    :loading="form.processing"
                    >Save user</v-btn
                >
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
