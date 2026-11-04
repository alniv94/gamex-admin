<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import type { DataTableHeader } from 'vuetify';
import { index, store } from '@/actions/App/Http/Controllers/SettingsSmsController';
import AdminDataTable from '@/components/AdminDataTable.vue';
import AdminTableToolbar from '@/components/AdminTableToolbar.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import type { PageResult } from '@/types/ending';

interface SmsPlayer {
    id: number;
    player_name: string;
    mobile_number: string;
}
const props = defineProps<{
    players: PageResult<SmsPlayer>;
    totalRecipients: number;
    submissionId: string;
    filters: { search?: string; direction?: 'asc' | 'desc' };
}>();
const search = ref(props.filters.search ?? '');
const loading = ref(false);
const selectedIds = ref<number[]>([]);
const dialog = ref(false);
const reviewing = ref(false);
const form = useForm({ request_id: '', message: '', blast: false, player_ids: [] as number[] });
const headers: DataTableHeader<SmsPlayer>[] = [
    { title: 'Select players', key: 'selection', sortable: false, width: 60 },
    { title: 'Player name', key: 'player_name' },
    { title: 'Mobile number', key: 'mobile_number', sortable: false },
];
const visibleIds = computed(() => props.players.data.map((player) => player.id));
const allVisibleSelected = computed(
    () =>
        visibleIds.value.length > 0 &&
        visibleIds.value.every((id) => selectedIds.value.includes(id)),
);
const someVisibleSelected = computed(
    () =>
        !allVisibleSelected.value && visibleIds.value.some((id) => selectedIds.value.includes(id)),
);
const recipientCount = computed(() =>
    form.blast ? props.totalRecipients : form.player_ids.length,
);
const messageLength = computed(() => Array.from(form.message).length);
const canReview = computed(
    () => form.message.trim().length > 0 && messageLength.value <= 160 && recipientCount.value > 0,
);
const submissionError = computed(
    () =>
        (form.errors as Record<string, string>).delivery ||
        form.errors.request_id ||
        form.errors.player_ids,
);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

function filter(direction = props.filters.direction ?? 'asc') {
    clearTimeout(searchTimer);
    router.get(
        index.url(),
        { search: search.value, sort: 'player_name', direction, per_page: props.players.per_page },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
}
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => filter(), 350);
}
function submitSearch(event: KeyboardEvent) {
    if (event.target instanceof HTMLInputElement) filter();
}
onBeforeUnmount(() => clearTimeout(searchTimer));
function togglePlayer(id: number) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((selected) => selected !== id)
        : [...selectedIds.value, id];
}
function togglePage() {
    selectedIds.value = allVisibleSelected.value
        ? selectedIds.value.filter((id) => !visibleIds.value.includes(id))
        : [...new Set([...selectedIds.value, ...visibleIds.value])];
}
function compose(blast: boolean) {
    if (form.processing || (!blast && !selectedIds.value.length)) return;
    form.reset();
    form.clearErrors();
    form.request_id = props.submissionId;
    form.blast = blast;
    form.player_ids = blast ? [] : [...selectedIds.value];
    reviewing.value = false;
    dialog.value = true;
}
function review() {
    if (canReview.value && !form.processing) reviewing.value = true;
}
function send() {
    if (!reviewing.value || !canReview.value || form.processing) return;
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = false;
            selectedIds.value = [];
            form.reset();
        },
        onError: () => (reviewing.value = false),
    });
}
</script>

<template>
    <div>
        <Head title="SMS" />
        <div class="players-page">
            <div class="players-breadcrumb">Settings</div>
            <div class="mb-6">
                <h1 class="players-title mb-1">SMS</h1>
                <p class="text-body-2 text-medium-emphasis">
                    Send a text blast to all eligible players or message selected players.
                </p>
            </div>
            <v-card>
                <div class="d-flex align-center flex-wrap ga-2 pa-4 pb-0">
                    <v-btn
                        color="primary"
                        prepend-icon="mdi-message-bulleted"
                        :disabled="loading || !totalRecipients"
                        @click="compose(true)"
                        >Send text blast</v-btn
                    >
                    <v-btn
                        color="primary"
                        variant="tonal"
                        prepend-icon="mdi-message-text-outline"
                        :disabled="loading || !selectedIds.length || selectedIds.length > 5000"
                        @click="compose(false)"
                        >Send SMS</v-btn
                    >
                    <v-chip color="primary" variant="outlined" aria-live="polite">
                        {{ selectedIds.length.toLocaleString() }}
                        {{ selectedIds.length === 1 ? 'player' : 'players' }} selected
                    </v-chip>
                    <v-btn
                        v-if="selectedIds.length"
                        variant="text"
                        size="small"
                        @click="selectedIds = []"
                        >Clear selection</v-btn
                    >
                </div>
                <v-alert v-if="selectedIds.length > 5000" type="info" variant="tonal" class="ma-4">
                    Select up to 5,000 players per message, or use a text blast for all eligible
                    players.
                </v-alert>
                <AdminTableToolbar
                    v-model="search"
                    :total="players.total"
                    :loading="loading"
                    search-label="Search player name or mobile"
                    refresh-label="Refresh players"
                    @search="debounceSearch"
                    @keydown.enter="submitSearch"
                    @refresh="filter()"
                />
                <AdminDataTable
                    :headers="headers"
                    :items="players.data"
                    server
                    :toolbar="false"
                    :loading="loading"
                    title="SMS recipients"
                >
                    <template #headers>
                        <tr>
                            <th scope="col">
                                <v-checkbox-btn
                                    :model-value="allVisibleSelected"
                                    :indeterminate="someVisibleSelected"
                                    :disabled="loading || !players.data.length"
                                    aria-label="Select all players on this page"
                                    @update:model-value="togglePage"
                                />
                            </th>
                            <th
                                scope="col"
                                :aria-sort="
                                    filters.direction === 'desc' ? 'descending' : 'ascending'
                                "
                            >
                                <button
                                    type="button"
                                    class="sms-sort"
                                    :disabled="loading"
                                    @click="filter(filters.direction === 'desc' ? 'asc' : 'desc')"
                                >
                                    Player name
                                    <v-icon
                                        :icon="
                                            filters.direction === 'desc'
                                                ? 'mdi-arrow-down'
                                                : 'mdi-arrow-up'
                                        "
                                        size="14"
                                    />
                                </button>
                            </th>
                            <th scope="col">Mobile number</th>
                        </tr>
                    </template>
                    <template #item="{ item: player }">
                        <tr :key="player.id">
                            <td>
                                <v-checkbox-btn
                                    :model-value="selectedIds.includes(player.id)"
                                    :disabled="loading"
                                    :aria-label="'Select ' + player.player_name"
                                    @update:model-value="togglePlayer(player.id)"
                                />
                            </td>
                            <td>{{ player.player_name }}</td>
                            <td class="text-no-wrap">{{ player.mobile_number }}</td>
                        </tr>
                    </template>
                    <template #no-data>No eligible players found.</template>
                </AdminDataTable>
                <EndingPager :page="players" selectable :page-sizes="[10, 25, 50, 100, 500]" />
            </v-card>
        </div>
        <v-dialog
            v-model="dialog"
            max-width="560"
            scrollable
            :persistent="form.processing"
            :aria-label="form.blast ? 'Send text blast' : 'Send SMS'"
        >
            <v-card>
                <v-card-title>{{
                    reviewing ? 'Confirm SMS' : form.blast ? 'Send text blast' : 'Send SMS'
                }}</v-card-title>
                <v-divider />
                <v-card-text>
                    <v-alert :type="form.blast ? 'warning' : 'info'" variant="tonal" class="mb-4">
                        <template v-if="form.blast">
                            This message will be sent to all
                            {{ recipientCount.toLocaleString() }} eligible players, regardless of
                            your search or selection.
                        </template>
                        <template v-else>
                            This message will be sent to
                            {{ recipientCount.toLocaleString() }} selected
                            {{ recipientCount === 1 ? 'player' : 'players' }}.
                        </template>
                        SMS credits will be used when you confirm sending.
                    </v-alert>
                    <v-alert
                        v-if="submissionError"
                        type="error"
                        variant="tonal"
                        class="mb-4"
                        role="alert"
                    >
                        {{ submissionError }}
                    </v-alert>
                    <div v-if="reviewing">
                        <div class="text-subtitle-2 mb-2">Message</div>
                        <p class="sms-preview text-body-1">{{ form.message }}</p>
                        <p class="text-caption text-medium-emphasis mt-3">
                            {{ messageLength }} / 160 characters
                        </p>
                    </div>
                    <form v-else id="sms-compose" @submit.prevent="review">
                        <v-textarea
                            v-model="form.message"
                            label="Compose your message"
                            variant="outlined"
                            rows="6"
                            counter="160"
                            :disabled="form.processing"
                            :error-messages="
                                form.errors.message ||
                                (messageLength > 160 ? 'Use 160 characters or fewer.' : '')
                            "
                            autofocus
                        />
                    </form>
                </v-card-text>
                <v-divider />
                <v-card-actions>
                    <v-spacer />
                    <v-btn :disabled="form.processing" @click="dialog = false">Cancel</v-btn>
                    <v-btn v-if="reviewing" :disabled="form.processing" @click="reviewing = false"
                        >Edit message</v-btn
                    >
                    <v-btn
                        v-if="reviewing"
                        color="primary"
                        variant="flat"
                        :loading="form.processing"
                        @click="send"
                    >
                        {{ form.blast ? 'Confirm text blast' : 'Confirm send' }}
                    </v-btn>
                    <v-btn
                        v-else
                        color="primary"
                        variant="flat"
                        type="submit"
                        form="sms-compose"
                        :disabled="!canReview"
                    >
                        Review message
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<style scoped>
.sms-sort {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font: inherit;
    text-transform: inherit;
    color: inherit;
}
.sms-preview {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
</style>
