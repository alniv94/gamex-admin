<script setup lang="ts">
import AdminFilterDialog from '@/components/AdminFilterDialog.vue';
import { useTableFilters } from '@/composables/useTableFilters';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import { exportTableItems } from '@/composables/useTableExport';
import AdminDataTable from '@/components/AdminDataTable.vue';
import type { DataTableHeader } from 'vuetify';
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import SettingsUserController from '@/actions/App/Http/Controllers/SettingsUserController';
import EndingPager from '@/components/ending/EndingPager.vue';
import AdminUserForm from '@/components/settings/AdminUserForm.vue';
import AdminRoleForm from '@/components/settings/AdminRoleForm.vue';
import { usePermissions } from '@/composables/usePermissions';
import type { AdminPageProps } from '@/types';
import type { PageResult } from '@/types/ending';
import type { AdminPermission, ManagedRole, ManagedUser } from '@/types/admin-users';

const props = defineProps<{
    users: PageResult<ManagedUser>;
    roles: ManagedRole[];
    permissions: AdminPermission[];
    filters: { search?: string; status?: string };
}>();
const page = usePage<AdminPageProps>();
const { can } = usePermissions();
const canManage = computed(() => can('Manage users and roles'));
const tab = ref('users');
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const userDialog = ref(false);
const roleDialog = ref(false);
const selectedUser = ref<ManagedUser | null>(null);
const selectedRole = ref<ManagedRole | null>(null);
const statusDialog = ref(false);
const statusUser = ref<ManagedUser | null>(null);
const statusForm = useForm({ status: 'Inactive', version: 0 });
const success = computed(() => (typeof page.flash.success === 'string' ? page.flash.success : ''));
function filter() {
    router.get(
        SettingsUserController.index.url(),
        { search: search.value, status: status.value, per_page: props.users.per_page },
        { preserveState: true, preserveScroll: true, onSuccess: () => (filterOpen.value = false) },
    );
}
function editUser(user: ManagedUser) {
    selectedUser.value = user;
    userDialog.value = true;
}
function editRole(role: ManagedRole | null) {
    selectedRole.value = role;
    roleDialog.value = true;
}
function confirmStatus(user: ManagedUser) {
    statusUser.value = user;
    statusForm.clearErrors();
    statusForm.status = user.status === 'Active' ? 'Inactive' : 'Active';
    statusForm.version = user.version;
    statusDialog.value = true;
}
function changeStatus() {
    if (!statusUser.value) return;
    statusForm.submit(SettingsUserController.status(statusUser.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            statusDialog.value = false;
        },
    });
}
function roleNames(ids: number[]) {
    return props.roles
        .filter((role) => ids.includes(role.id))
        .map((role) => role.name)
        .join(', ');
}

const tableHeaders: DataTableHeader[] = [
    { title: 'Actions', key: 'actions', sortable: false },
    { title: 'Name', key: 'name', sortable: true },
    { title: 'Mobile number', key: 'mobile_number', sortable: true },
    { title: 'Email', key: 'email', sortable: true },
    { title: 'Status', key: 'status', sortable: true },
    { title: 'Roles', key: 'roles', sortable: true },
    { title: 'Permissions', key: 'effective_permissions', sortable: true },
];
const tableHeaders2: DataTableHeader[] = [
    { title: 'Actions', key: 'actions', sortable: false },
    { title: 'Role', key: 'name', sortable: true },
    { title: 'Permissions', key: 'permissions', sortable: true },
    { title: 'Assigned users', key: 'users_count', sortable: true },
];

const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
    () => props.filters,
    () => ['search', { key: 'status', resetValue: '' }],
    (changes) => {
        search.value = String(changes.search ?? '');
        status.value = String(changes.status ?? '');
        filter();
    },
    undefined,
);
const filterOpen = ref(false);
const draft = reactive({ status: '' });
function openFilters() {
    draft.status = status.value;
    filterOpen.value = true;
}
function applyFilters() {
    status.value = draft.status;
    filter();
}
</script>

<template>
    <div>
        <Head title="Users" />
        <div class="players-page">
            <div class="players-breadcrumb">Settings</div>
            <div class="users-heading">
                <div>
                    <h1 class="players-title mb-1">Users</h1>
                    <p class="text-body-2 text-medium-emphasis">
                        Manage your admin team and the access each person needs.
                    </p>
                </div>
                <v-btn
                    v-if="canManage"
                    :href="SettingsUserController.create.url()"
                    color="primary"
                    prepend-icon="mdi-account-plus-outline"
                    @click.prevent="router.visit(SettingsUserController.create())"
                    >Create user</v-btn
                >
            </div>
            <v-alert v-if="success" type="success" variant="tonal" class="mb-4" role="status">{{
                success
            }}</v-alert>
            <v-card>
                <v-tabs v-model="tab" color="primary"
                    ><v-tab value="users">Users</v-tab><v-tab value="roles">Roles</v-tab></v-tabs
                >
                <v-divider />
                <template v-if="tab === 'users'">
                    <AdminTableToolbar
                        :filters-open="filterOpen"
                        filterable
                        @filter="openFilters"
                        :active-filters="activeFilters"
                        @remove-filter="removeFilter"
                        @reset-filters="resetTableFilters"
                        :total="users.total"
                        v-model="search"
                        search-label="Search users"
                        refresh-label="Refresh users"
                        :exports="[
                            {
                                title: 'Current page (CSV)',
                                action: () =>
                                    exportTableItems('users.csv', users.data, tableHeaders),
                            },
                        ]"
                        @search="filter"
                        @refresh="router.reload()"
                    ></AdminTableToolbar>
                    <AdminDataTable
                        :headers="tableHeaders"
                        :items="users.data"
                        server
                        :toolbar="false"
                        title="Users"
                    >
                        <template #headers>
                            <tr>
                                <th>Actions</th>
                                <th>Name</th>
                                <th>Mobile number</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Roles</th>
                                <th>Permissions</th>
                            </tr>
                        </template>
                        <template #item="{ item: user }"
                            ><tr :key="user.id">
                                <td class="text-no-wrap">
                                    <v-btn
                                        :icon="
                                            canManage
                                                ? 'mdi-square-edit-outline'
                                                : 'mdi-eye-outline'
                                        "
                                        variant="text"
                                        color="primary"
                                        size="small"
                                        :aria-label="(canManage ? 'Edit ' : 'View ') + user.name"
                                        @click="editUser(user)"
                                    />
                                    <v-btn
                                        v-if="canManage"
                                        :icon="
                                            user.status === 'Active'
                                                ? 'mdi-account-cancel-outline'
                                                : 'mdi-account-check-outline'
                                        "
                                        variant="text"
                                        :color="user.status === 'Active' ? 'error' : 'success'"
                                        size="small"
                                        :disabled="user.id === page.props.auth.user?.id"
                                        :aria-label="
                                            (user.status === 'Active'
                                                ? 'Deactivate '
                                                : 'Activate ') + user.name
                                        "
                                        @click="confirmStatus(user)"
                                    />
                                </td>
                                <td>
                                    {{ user.name || 'Unnamed user'
                                    }}<v-chip
                                        v-if="user.id === page.props.auth.user?.id"
                                        size="x-small"
                                        class="ml-2"
                                        >You</v-chip
                                    >
                                </td>
                                <td class="text-no-wrap">{{ user.mobile_number || '—' }}</td>
                                <td>{{ user.email || '—' }}</td>
                                <td>
                                    <v-chip
                                        :color="user.status === 'Active' ? 'success' : 'default'"
                                        size="small"
                                        >{{ user.status }}</v-chip
                                    >
                                </td>
                                <td>{{ roleNames(user.roles) || 'No roles' }}</td>
                                <td class="text-no-wrap">
                                    <div>{{ user.effective_permissions.length }} assigned</div>
                                    <div class="text-caption text-medium-emphasis">
                                        {{ user.inherited_permissions.length }} inherited ·
                                        {{ user.direct_permissions.length }} direct
                                    </div>
                                </td>
                            </tr></template
                        >
                        <template #no-data>No users found.</template>
                    </AdminDataTable>
                    <EndingPager :page="users" selectable />
                </template>
                <template v-else>
                    <div class="d-flex align-center flex-wrap ga-3 pa-4">
                        <p class="text-body-2 flex-grow-1">
                            Group permissions into roles, then assign roles to users.
                        </p>
                        <v-btn
                            v-if="canManage"
                            color="primary"
                            prepend-icon="mdi-plus"
                            @click="editRole(null)"
                            >Create role</v-btn
                        >
                    </div>
                    <AdminDataTable
                        :headers="tableHeaders2"
                        :items="roles"
                        :toolbar="true"
                        title="Roles"
                    >
                        <template #item="{ item: role }"
                            ><tr :key="role.id">
                                <td>
                                    <v-btn
                                        :icon="
                                            canManage
                                                ? 'mdi-square-edit-outline'
                                                : 'mdi-eye-outline'
                                        "
                                        variant="text"
                                        color="primary"
                                        size="small"
                                        :aria-label="
                                            (canManage ? 'Edit role ' : 'View role ') + role.name
                                        "
                                        @click="editRole(role)"
                                    />
                                </td>
                                <td>{{ role.name }}</td>
                                <td>{{ role.permissions.length }}</td>
                                <td>{{ role.users_count }}</td>
                            </tr></template
                        >
                        <template #no-data>
                            No roles yet. Existing users retain their direct permissions.
                        </template>
                    </AdminDataTable>
                </template>
            </v-card>
        </div>
        <AdminUserForm
            v-model="userDialog"
            :user="selectedUser"
            :roles="roles"
            :permissions="permissions"
            :readonly="!canManage"
        />
        <AdminRoleForm
            v-model="roleDialog"
            :role="selectedRole"
            :permissions="permissions"
            :readonly="!canManage"
        />
        <v-dialog
            v-model="statusDialog"
            max-width="500"
            :persistent="statusForm.processing"
            aria-label="Change account status"
        >
            <v-card>
                <v-card-title>{{
                    statusForm.status === 'Inactive' ? 'Deactivate user' : 'Activate user'
                }}</v-card-title>
                <v-card-text>
                    <v-alert
                        v-if="statusForm.hasErrors"
                        type="error"
                        variant="tonal"
                        class="mb-3"
                        role="alert"
                        ><div v-for="(error, key) in statusForm.errors" :key="key">
                            {{ error }}
                        </div></v-alert
                    >
                    <p v-if="statusForm.status === 'Inactive'">
                        Deactivate {{ statusUser?.name }}? Their active sessions and pending sign-in
                        codes will be revoked. Their role and permission assignments will be kept.
                    </p>
                    <p v-else>
                        Activate {{ statusUser?.name }}? They will be able to sign in with their
                        existing roles and direct permissions.
                    </p>
                </v-card-text>
                <v-card-actions
                    ><v-spacer /><v-btn
                        :disabled="statusForm.processing"
                        @click="statusDialog = false"
                        >Cancel</v-btn
                    ><v-btn
                        :color="statusForm.status === 'Inactive' ? 'error' : 'primary'"
                        variant="flat"
                        :loading="statusForm.processing"
                        @click="changeStatus"
                        >{{
                            statusForm.status === 'Inactive' ? 'Deactivate user' : 'Activate user'
                        }}</v-btn
                    ></v-card-actions
                >
            </v-card>
        </v-dialog>
        <AdminFilterDialog v-model="filterOpen" @apply="applyFilters" @reset="resetTableFilters"
            ><v-select
                v-model="draft.status"
                label="Account status"
                :items="[
                    { title: 'All statuses', value: '' },
                    { title: 'Active', value: 'Active' },
                    { title: 'Inactive', value: 'Inactive' },
                ]"
                variant="outlined"
                density="compact"
                hide-details
        /></AdminFilterDialog>
    </div>
</template>

<style scoped>
.users-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}
.users-search {
    flex: 1 1 240px;
    max-width: 360px;
}
.users-status {
    flex: 0 1 180px;
    min-width: 150px;
}
</style>
