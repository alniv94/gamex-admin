<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { index, store } from '@/actions/App/Http/Controllers/SettingsUserController';
import PermissionPicker from '@/components/settings/PermissionPicker.vue';
import type { AdminPermission, ManagedRole } from '@/types/admin-users';

const props = defineProps<{ roles: ManagedRole[]; permissions: AdminPermission[] }>();
const form = useForm('CreateAdminUser', {
    name: '',
    mobile_number: '',
    email: '',
    roles: [] as number[],
    permissions: [] as string[],
});
const errorSummary = ref<HTMLElement | null>(null);
const selectedRoles = computed(() => props.roles.filter((role) => form.roles.includes(role.id)));
const inherited = computed(() => {
    const sources: Record<string, string[]> = {};
    for (const role of selectedRoles.value) {
        for (const permission of role.permissions) (sources[permission] ??= []).push(role.name);
    }
    return sources;
});
const inheritedCount = computed(() => Object.keys(inherited.value).length);
const effectiveCount = computed(
    () => new Set([...Object.keys(inherited.value), ...form.permissions]).size,
);
const initials = computed(() =>
    form.name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((name) => name[0])
        .join('')
        .toUpperCase(),
);
function save() {
    if (form.processing) return;
    form.submit(store(), {
        onSuccess: () => form.reset(),
        onError: async () => {
            await nextTick();
            errorSummary.value?.focus();
            errorSummary.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
    });
}
function cancel() {
    if (form.processing) return;
    form.reset();
    router.visit(index());
}
</script>

<template>
    <div>
        <Head title="Create user" />
        <div class="create-user-page">
            <nav class="create-breadcrumb" aria-label="Breadcrumb">
                <span>Settings</span><v-icon icon="mdi-chevron-right" size="16" />
                <Link :href="index()">Users</Link><v-icon icon="mdi-chevron-right" size="16" />
                <span aria-current="page">Create user</span>
            </nav>
            <header class="create-heading">
                <div class="create-heading-icon">
                    <v-icon icon="mdi-account-plus-outline" size="28" />
                </div>
                <div>
                    <h1>Create user</h1>
                    <p>Add an administrator and choose the access they need.</p>
                </div>
            </header>

            <form aria-label="Create admin user" novalidate @submit.prevent="save">
                <div v-if="form.hasErrors" ref="errorSummary" class="create-errors" tabindex="-1">
                    <v-alert
                        type="error"
                        variant="tonal"
                        title="Check the details below"
                        role="alert"
                    >
                        <div v-for="(error, key) in form.errors" :key="key">{{ error }}</div>
                    </v-alert>
                </div>
                <div class="create-grid">
                    <div class="create-sections">
                        <section class="create-card" aria-labelledby="account-heading">
                            <div class="section-heading">
                                <span class="section-number">01</span>
                                <div>
                                    <h2 id="account-heading">Account details</h2>
                                    <p>The mobile number will be used for sign-in.</p>
                                </div>
                            </div>
                            <div class="account-fields">
                                <v-text-field
                                    v-model="form.name"
                                    label="Name"
                                    placeholder="Enter the administrator's name"
                                    variant="outlined"
                                    :error-messages="form.errors.name"
                                    @update:model-value="form.clearErrors('name')"
                                    :disabled="form.processing"
                                    maxlength="255"
                                    autocomplete="off"
                                    aria-required="true"
                                    hide-details="auto"
                                />
                                <div class="contact-fields">
                                    <v-text-field
                                        v-model="form.mobile_number"
                                        label="Mobile number"
                                        placeholder="0917 123 4567"
                                        variant="outlined"
                                        :error-messages="form.errors.mobile_number"
                                        @update:model-value="form.clearErrors('mobile_number')"
                                        :disabled="form.processing"
                                        type="tel"
                                        autocomplete="off"
                                        aria-required="true"
                                        hint="Philippine mobile number"
                                        persistent-hint
                                    />
                                    <v-text-field
                                        v-model="form.email"
                                        label="Email (optional)"
                                        placeholder="name@example.com"
                                        variant="outlined"
                                        :error-messages="form.errors.email"
                                        @update:model-value="form.clearErrors('email')"
                                        :disabled="form.processing"
                                        type="email"
                                        autocomplete="off"
                                        hide-details="auto"
                                    />
                                </div>
                            </div>
                        </section>

                        <section class="create-card" aria-labelledby="roles-heading">
                            <div class="section-heading">
                                <span class="section-number">02</span>
                                <div>
                                    <h2 id="roles-heading">Assign roles</h2>
                                    <p>
                                        Start with the permissions shared by a team or
                                        responsibility.
                                    </p>
                                </div>
                                <span class="optional-label">Optional</span>
                            </div>
                            <v-autocomplete
                                v-model="form.roles"
                                :items="roles"
                                item-title="name"
                                item-value="id"
                                label="Roles"
                                placeholder="Select one or more roles"
                                multiple
                                chips
                                closable-chips
                                variant="outlined"
                                :error-messages="form.errors.roles"
                                :disabled="form.processing || !roles.length"
                                hide-details="auto"
                            />
                            <div v-if="selectedRoles.length" class="role-previews">
                                <div
                                    v-for="role in selectedRoles"
                                    :key="role.id"
                                    class="role-preview"
                                >
                                    <v-icon
                                        icon="mdi-shield-account-outline"
                                        size="20"
                                        color="primary"
                                    />
                                    <span>{{ role.name }}</span>
                                    <span class="role-count"
                                        >{{ role.permissions.length }} permissions</span
                                    >
                                </div>
                            </div>
                            <p v-else class="section-note">
                                {{
                                    roles.length
                                        ? 'You can combine roles. Permissions from all selected roles are included.'
                                        : 'No roles yet. You can use direct permissions now and assign a role later.'
                                }}
                            </p>
                        </section>

                        <section class="create-card" aria-labelledby="permissions-heading">
                            <div class="section-heading">
                                <span class="section-number">03</span>
                                <div>
                                    <h2 id="permissions-heading">Individual access</h2>
                                    <p>Add permissions for this user alongside their roles.</p>
                                </div>
                                <span class="optional-label">Optional</span>
                            </div>
                            <PermissionPicker
                                v-model="form.permissions"
                                :permissions="permissions"
                                :inherited="inherited"
                                :readonly="form.processing"
                                direct
                            />
                        </section>
                    </div>

                    <aside class="access-summary" aria-label="Access summary">
                        <div class="summary-heading">
                            <v-icon icon="mdi-shield-check-outline" size="21" />
                            <h2>Access summary</h2>
                        </div>
                        <div class="identity-preview">
                            <div class="identity-avatar">
                                <span v-if="initials">{{ initials }}</span
                                ><v-icon v-else icon="mdi-account-outline" size="27" />
                            </div>
                            <div class="identity-copy">
                                <strong>{{ form.name.trim() || 'New administrator' }}</strong
                                ><span>{{
                                    form.mobile_number.trim() || 'Mobile number not entered'
                                }}</span>
                            </div>
                        </div>
                        <dl class="access-counts">
                            <div>
                                <dt>Roles selected</dt>
                                <dd>{{ selectedRoles.length }}</dd>
                            </div>
                            <div>
                                <dt>Permissions from roles</dt>
                                <dd>{{ inheritedCount }}</dd>
                            </div>
                            <div>
                                <dt>Direct grants</dt>
                                <dd>{{ form.permissions.length }}</dd>
                            </div>
                            <div class="access-total">
                                <dt>Total permissions</dt>
                                <dd>{{ effectiveCount }}</dd>
                            </div>
                        </dl>
                        <p class="summary-note">
                            {{
                                effectiveCount
                                    ? 'Permissions shared by roles and direct grants are counted once.'
                                    : 'This user will have no feature permissions until access is assigned.'
                            }}
                        </p>
                        <div class="sign-in-note">
                            <v-icon icon="mdi-cellphone-check" size="22" />
                            <div>
                                <strong>Ready to sign in</strong>
                                <p>
                                    The account will be active immediately. The user signs in with a
                                    mobile OTP.
                                </p>
                            </div>
                        </div>
                    </aside>
                </div>
                <div class="create-actions">
                    <span class="create-action-note"
                        ><v-icon icon="mdi-account-check-outline" size="18" /> Review access before
                        creating the account.</span
                    >
                    <div class="create-action-buttons">
                        <v-btn variant="text" :disabled="form.processing" @click="cancel"
                            >Cancel</v-btn
                        >
                        <v-btn
                            type="submit"
                            color="primary"
                            variant="flat"
                            prepend-icon="mdi-account-plus-outline"
                            :loading="form.processing"
                            >Create user</v-btn
                        >
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>

<style scoped>
.create-user-page {
    max-width: 1440px;
    margin: 0 auto;
    padding: 24px;
}
.create-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #77818e;
    font-size: 12px;
    margin-bottom: 22px;
}
.create-breadcrumb a {
    color: #495b70;
    text-decoration: none;
}
.create-breadcrumb a:hover {
    color: rgb(var(--v-theme-primary));
    text-decoration: underline;
}
.create-heading {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 28px;
}
.create-heading-icon {
    width: 54px;
    height: 54px;
    border: 1px solid #c9edf8;
    background: #eefaff;
    border-radius: 14px;
    color: #009aca;
    display: grid;
    place-items: center;
    flex-shrink: 0;
}
.create-heading h1 {
    font-size: 25px;
    line-height: 1.3;
    letter-spacing: -0.5px;
    color: #1d2b3b;
    margin: 0 0 5px;
}
.create-heading p,
.section-heading p {
    color: #687585;
    font-size: 13px;
    line-height: 1.65;
    margin: 0;
}
.create-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 290px;
    gap: 24px;
    align-items: start;
}
.create-sections {
    display: grid;
    gap: 20px;
    min-width: 0;
}
.create-card,
.access-summary {
    background: white;
    border: 1px solid #e5e9ef;
    border-radius: 12px;
}
.create-card {
    padding: 24px;
    min-width: 0;
}
.section-heading {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 24px;
}
.section-number {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: #f0f5f9;
    color: #607389;
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
}
.section-heading h2 {
    color: #243447;
    font-size: 16px;
    margin: 1px 0 4px;
}
.optional-label {
    margin-left: auto;
    font-size: 10px;
    background: #f5f7fa;
    color: #7a8795;
    padding: 4px 8px;
    border-radius: 5px;
}
.account-fields {
    display: grid;
    gap: 24px;
}
.contact-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    align-items: start;
}
.section-note,
.summary-note {
    font-size: 12px;
    color: #738091;
    line-height: 1.75;
    margin: 14px 0 0;
}
.role-previews {
    display: grid;
    gap: 8px;
    margin-top: 16px;
}
.role-preview {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    padding: 12px;
    background: #f6fafc;
    border: 1px solid #e6eff4;
    border-radius: 8px;
    font-size: 12px;
}
.role-count {
    margin-left: auto;
    color: #718297;
    font-size: 11px;
}
.access-summary {
    padding: 22px;
    position: sticky;
    top: 88px;
}
.summary-heading {
    display: flex;
    align-items: center;
    gap: 9px;
    color: #3c546a;
    margin-bottom: 23px;
}
.summary-heading h2 {
    font-size: 14px;
}
.identity-preview {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 22px;
    border-bottom: 1px solid #edf0f4;
}
.identity-avatar {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #eaf7fc;
    color: #008dbb;
    font-weight: 600;
    flex-shrink: 0;
}
.identity-copy {
    display: grid;
    gap: 4px;
    min-width: 0;
    overflow-wrap: anywhere;
}
.identity-copy strong {
    font-size: 13px;
    color: #304258;
    font-weight: 500;
}
.identity-copy span {
    font-size: 11px;
    color: #7c8999;
}
.access-counts {
    margin-top: 20px;
}
.access-counts > div {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 14px;
    font-size: 12px;
    color: #6c7a8c;
}
.access-counts dd {
    color: #304258;
    font-weight: 600;
}
.access-counts .access-total {
    border-top: 1px solid #edf0f4;
    padding-top: 16px;
    margin-top: 18px;
    align-items: center;
    color: #304258;
    font-weight: 500;
}
.access-total dd {
    color: #0099ca;
    font-size: 26px;
    line-height: 1;
}
.sign-in-note {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    border-radius: 8px;
    background: #f2f9f6;
    padding: 14px;
    margin-top: 22px;
    color: #3b7b62;
}
.sign-in-note strong {
    font-size: 12px;
    font-weight: 500;
}
.sign-in-note p {
    font-size: 11px;
    line-height: 1.8;
    margin: 5px 0 0;
    color: #5c8071;
}
.create-errors {
    margin-bottom: 20px;
    scroll-margin-top: 88px;
}
.create-actions {
    position: sticky;
    bottom: 16px;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 20px;
    margin-top: 24px;
    background: white;
    border: 1px solid #e1e7ef;
    border-radius: 10px;
    box-shadow: 0 4px 20px #253b5010;
}
.create-action-note {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #7b8797;
    font-size: 11px;
}
.create-action-buttons {
    display: flex;
    gap: 8px;
    margin-left: auto;
}
@media (max-width: 1050px) {
    .create-grid {
        grid-template-columns: minmax(0, 1fr);
    }
    .access-summary {
        position: static;
    }
}
@media (max-width: 600px) {
    .create-user-page {
        padding: 20px 16px;
    }
    .create-heading {
        gap: 12px;
        margin-bottom: 22px;
    }
    .create-heading h1 {
        font-size: 23px;
    }
    .create-heading-icon {
        width: 44px;
        height: 44px;
        border-radius: 11px;
    }
    .create-card {
        padding: 20px 16px;
    }
    .contact-fields {
        grid-template-columns: minmax(0, 1fr);
        gap: 24px;
    }
    .section-heading {
        gap: 9px;
    }
    .optional-label {
        display: none;
    }
    .create-action-note {
        display: none;
    }
    .create-actions {
        padding: 12px;
        bottom: 8px;
    }
    .create-action-buttons {
        width: 100%;
        justify-content: space-between;
    }
}
</style>
