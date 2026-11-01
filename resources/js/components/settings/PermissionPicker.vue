<script setup lang="ts">
import { computed, ref } from 'vue';
import type { AdminPermission } from '@/types/admin-users';

const selected = defineModel<string[]>({ required: true });
const props = withDefaults(
    defineProps<{
        permissions: AdminPermission[];
        inherited?: Record<string, string[]>;
        direct?: boolean;
        readonly?: boolean;
    }>(),
    { inherited: () => ({}), direct: false, readonly: false },
);
const search = ref('');
const assignedOnly = ref(false);
const term = computed(() => (search.value ?? '').trim().toLowerCase());
const isEffective = (name: string) =>
    selected.value.includes(name) || Boolean(props.inherited[name]?.length);
const groups = computed(() => {
    const result: Record<string, AdminPermission[]> = {};
    for (const permission of props.permissions) {
        if (assignedOnly.value && !isEffective(permission.name)) continue;
        if (
            term.value &&
            ![permission.label, permission.name, permission.group].some((value) =>
                value.toLowerCase().includes(term.value),
            )
        )
            continue;
        (result[permission.group] ??= []).push(permission);
    }
    return result;
});
const effectiveCount = computed(
    () => new Set([...selected.value, ...Object.keys(props.inherited)]).size,
);
const icons: Record<string, string> = {
    Players: 'mdi-account-group-outline',
    Ending: 'mdi-basketball',
    Cashin: 'mdi-cash-plus',
    Cashout: 'mdi-cash-minus',
    Settings: 'mdi-cog-outline',
};
</script>

<template>
    <div class="permission-picker">
        <div class="permission-title">
            <h3>{{ direct ? 'Direct permissions' : 'Role permissions' }}</h3>
            <span class="effective-badge">{{ effectiveCount }} effective</span>
        </div>
        <p v-if="direct" class="permission-description">
            Direct grants add to role permissions. Removing a direct grant keeps access inherited
            from a role.
        </p>
        <div class="permission-filters">
            <v-text-field
                v-model="search"
                label="Search permissions"
                placeholder="Search by feature or action"
                prepend-inner-icon="mdi-magnify"
                variant="outlined"
                density="compact"
                hide-details
                clearable
                @click:clear="search = ''"
            />
            <v-switch
                v-model="assignedOnly"
                label="Assigned only"
                color="primary"
                density="compact"
                hide-details
            />
        </div>
        <div v-if="!Object.keys(groups).length" class="permission-empty">
            <v-icon icon="mdi-shield-search" size="30" />
            <strong>{{
                assignedOnly ? 'No assigned permissions match' : 'No permissions found'
            }}</strong>
            <span>Try another search{{ assignedOnly ? ' or turn off Assigned only' : '' }}.</span>
        </div>
        <div class="permission-groups">
            <details
                v-for="(items, group) in groups"
                :key="group"
                class="permission-group"
                :open="Boolean(term) || assignedOnly"
            >
                <summary>
                    <v-icon
                        :icon="icons[group] || 'mdi-folder-outline'"
                        size="20"
                        class="group-icon"
                    />
                    <span class="group-name">{{ group }}</span>
                    <span class="group-count"
                        >{{ items.filter((item) => isEffective(item.name)).length }} /
                        {{ items.length }}</span
                    >
                    <v-icon icon="mdi-chevron-down" size="18" class="group-chevron" />
                </summary>
                <div class="permission-options">
                    <div
                        v-for="permission in items"
                        :key="permission.name"
                        class="permission-option"
                        :class="{
                            'permission-option-selected': selected.includes(permission.name),
                        }"
                    >
                        <v-checkbox
                            v-model="selected"
                            :value="permission.name"
                            :label="permission.label"
                            :aria-label="(direct ? 'Direct grant: ' : 'Grant: ') + permission.label"
                            :disabled="readonly"
                            density="compact"
                            hide-details
                            color="primary"
                        />
                        <p
                            v-if="permission.name === 'Manage users and roles'"
                            class="permission-note"
                        >
                            Can create or deactivate administrators and grant any permission.
                        </p>
                        <p v-if="inherited[permission.name]?.length" class="inherited-note">
                            <v-icon icon="mdi-link-variant" size="13" /> From role:
                            {{ inherited[permission.name].join(', ') }}
                        </p>
                    </div>
                </div>
            </details>
        </div>
    </div>
</template>

<style scoped>
.permission-title {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 8px;
}
.permission-title h3 {
    font-size: 14px;
    color: #304258;
}
.effective-badge {
    padding: 3px 9px;
    border-radius: 20px;
    background: #eaf8fe;
    color: #008cbb;
    font-size: 11px;
    font-weight: 500;
}
.permission-description {
    font-size: 12px;
    line-height: 1.75;
    color: #718094;
    margin-bottom: 18px;
}
.permission-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    margin-bottom: 18px;
}
.permission-filters > .v-text-field {
    flex: 1 1 230px;
    min-width: 0;
}
.permission-filters > .v-switch {
    flex: 0 0 auto;
}
.permission-filters :deep(.v-switch .v-label) {
    font-size: 12px;
    white-space: nowrap;
}
.permission-groups {
    display: grid;
    gap: 10px;
}
.permission-group {
    border: 1px solid #e5eaf0;
    border-radius: 8px;
    overflow: hidden;
}
.permission-group summary {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px;
    cursor: pointer;
    list-style: none;
    background: #f9fbfd;
}
.permission-group summary::-webkit-details-marker {
    display: none;
}
.permission-group summary:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: -2px;
}
.permission-group summary:hover {
    background: #f1f7fb;
}
.group-icon {
    color: #7b91a7;
}
.group-name {
    font-size: 12px;
    font-weight: 500;
    color: #40556c;
}
.group-count {
    margin-left: auto;
    background: white;
    border: 1px solid #e5eaf0;
    border-radius: 5px;
    font-size: 10px;
    color: #7c8da0;
    padding: 2px 6px;
}
.group-chevron {
    color: #8a9aad;
    transition: transform 150ms;
}
.permission-group[open] .group-chevron {
    transform: rotate(180deg);
}
.permission-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
    gap: 8px;
    padding: 12px;
    border-top: 1px solid #e5eaf0;
}
.permission-option {
    min-width: 0;
    padding: 6px 8px;
    border: 1px solid transparent;
    border-radius: 6px;
}
.permission-option-selected {
    background: #f0faff;
    border-color: #d8effa;
}
.permission-option :deep(.v-label) {
    white-space: normal;
    font-size: 12px;
    line-height: 1.6;
    opacity: 1;
    color: #3b4e62;
}
.permission-note,
.inherited-note {
    padding-left: 30px;
    font-size: 10px;
    line-height: 1.7;
    margin: 2px 0 4px;
    color: #7a8797;
}
.inherited-note {
    color: #008bb7;
}
.permission-empty {
    display: grid;
    justify-items: center;
    gap: 8px;
    padding: 28px 16px;
    text-align: center;
    color: #8a9aae;
    border: 1px dashed #dce4ed;
    border-radius: 8px;
}
.permission-empty strong {
    font-weight: 500;
    font-size: 13px;
    color: #5a6d83;
}
.permission-empty span {
    font-size: 12px;
}
</style>
