<script setup lang="ts">
import {
    assign,
    candidates,
    index,
    remove,
    resetPassword,
    cashins,
    show,
} from '@/actions/App/Http/Controllers/AgentController';
import { show as playerShow } from '@/actions/App/Http/Controllers/PlayerController';
import AgentForm from '@/components/agents/AgentForm.vue';
import AgentShell from '@/components/agents/AgentShell.vue';
import EndingPager from '@/components/ending/EndingPager.vue';
import { useAdminApi } from '@/composables/useAdminApi';
import { usePermissions } from '@/composables/usePermissions';
import type { AgentPlayer, AgentRow } from '@/types/agents';
import { agentName } from '@/types/agents';
import type { PageResult } from '@/types/ending';
import { playerDate, playerMoney } from '@/types/players';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    account: AgentRow;
    players: PageResult<AgentPlayer>;
    outlets: { id: number; outlet_name: string }[];
    search: string;
    ready: boolean;
}>();
const { can } = usePermissions();
const page = usePage();
const query = new URLSearchParams(page.url.split('?')[1]);
const editor = ref(false);
const assigning = ref(props.ready && query.has('assign'));
const resetting = ref(props.ready && query.has('reset'));
const search = ref(props.search);
const lookup = ref('');
const finding = ref(false);
const searched = ref(false);
const lookupError = ref('');
const matches = ref<{ id: number; name: string; mobile_number: string }[]>([]);
const api = useAdminApi();
const assignment = useForm({ player_id: null as number | null });
const password = useForm({ password: '', password_confirmation: '' });
const removal = useForm({});
const removing = ref<AgentPlayer>();
watch(assigning, () => {
    lookup.value = '';
    matches.value = [];
    searched.value = false;
    lookupError.value = '';
    assignment.reset();
    assignment.clearErrors();
});
watch(resetting, () => {
    password.reset();
    password.clearErrors();
});
async function find() {
    if (lookup.value.trim().length < 3 || finding.value) return;
    finding.value = true;
    lookupError.value = '';
    assignment.player_id = null;
    matches.value = [];
    try {
        matches.value = (
            await api<{ records: typeof matches.value }>(
                candidates.url({ query: { search: lookup.value.trim() } }),
                {},
                'GET',
            )
        ).records;
        searched.value = true;
    } catch (failure) {
        lookupError.value =
            failure instanceof Error ? failure.message : 'Unable to search players.';
    } finally {
        finding.value = false;
    }
}
function addPlayer() {
    assignment.post(assign.url(props.account.id), {
        preserveScroll: true,
        onSuccess: () => (assigning.value = false),
    });
}
function changePassword() {
    password.put(resetPassword.url(props.account.id), {
        preserveScroll: true,
        onSuccess: () => (resetting.value = false),
        onFinish: () => password.reset(),
    });
}
function removePlayer() {
    if (removing.value)
        removal.delete(remove.url({ agent: props.account.id, link: removing.value.id }), {
            preserveScroll: true,
            onSuccess: () => (removing.value = undefined),
        });
}
function filterPlayers() {
    router.get(
        show.url(props.account.id),
        { search: search.value, per_page: props.players.per_page },
        { preserveState: true, preserveScroll: true },
    );
}
const assignmentError = computed(() => (assignment.errors as Record<string, string>).action);
const passwordError = computed(() => (password.errors as Record<string, string>).action);
const removalError = computed(() => (removal.errors as Record<string, string>).action);
</script>

<template>
    <AgentShell :title="agentName(account)" :ready="ready">
        <div class="d-flex flex-wrap ga-2 mb-4">
            <Link :href="index()" class="align-self-center mr-3">Back to agents</Link>
            <v-btn
                variant="tonal"
                prepend-icon="mdi-pencil-outline"
                :disabled="!ready"
                @click="editor = true"
                >Edit agent</v-btn
            >
            <v-btn
                variant="tonal"
                prepend-icon="mdi-key-outline"
                :disabled="!ready"
                @click="resetting = true"
                >Reset password</v-btn
            >
            <Link
                :href="cashins({ query: { agent_id: account.id } })"
                class="align-self-center ml-2"
                >View cash-ins</Link
            >
        </div>
        <v-alert
            v-if="Number(account.username_conflicts) > 0"
            type="warning"
            variant="tonal"
            class="mb-4"
            >This legacy username is shared by another agent. Set a unique username in Edit agent to
            resolve the conflict.</v-alert
        >
        <v-card class="mb-4 pa-4">
            <v-row>
                <v-col cols="12" sm="3"
                    ><div class="text-caption text-medium-emphasis">Username / status</div>
                    {{ account.username }} · {{ account.is_active }}</v-col
                >
                <v-col cols="12" sm="3"
                    ><div class="text-caption text-medium-emphasis">Mobile / email</div>
                    {{ account.mobile_number ? '+63' + account.mobile_number : '—' }}<br />{{
                        account.email_address || '—'
                    }}</v-col
                >
                <v-col cols="12" sm="3"
                    ><div class="text-caption text-medium-emphasis">Type / commission</div>
                    {{ account.type }} · {{ playerMoney(account.commission) }}%</v-col
                >
                <v-col cols="12" sm="3"
                    ><div class="text-caption text-medium-emphasis">Agent wallet</div>
                    PHP {{ playerMoney(account.wallet) }}</v-col
                >
            </v-row>
        </v-card>
        <v-card>
            <div class="d-flex flex-wrap align-center ga-3 pa-4">
                <div>
                    <h2 class="text-subtitle-1">Agent players</h2>
                    <span class="text-caption"
                        >{{ Number(account.player_count).toLocaleString() }} players ·
                        {{ Number(account.verified_count).toLocaleString() }} verified</span
                    >
                </div>
                <v-btn
                    color="primary"
                    prepend-icon="mdi-account-plus-outline"
                    :disabled="!ready"
                    @click="assigning = true"
                    >Add player</v-btn
                >
                <v-spacer />
                <form class="d-flex ga-2" @submit.prevent="filterPlayers">
                    <v-text-field
                        v-model="search"
                        label="Search players"
                        hide-details
                        density="compact"
                        clearable
                    /><v-btn
                        type="submit"
                        icon="mdi-magnify"
                        variant="text"
                        aria-label="Search assigned players"
                    />
                </form>
            </div>
            <v-table class="ending-table" density="compact">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Player</th>
                        <th>Mobile number</th>
                        <th>Verification</th>
                        <th>Status</th>
                        <th>Commission</th>
                        <th>Added at</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="player in players.data" :key="player.id">
                        <td>
                            <v-btn
                                icon="mdi-account-remove-outline"
                                color="error"
                                size="small"
                                variant="text"
                                :disabled="!ready"
                                :aria-label="`Remove ${player.name || 'unresolved player'}`"
                                @click="
                                    removing = player;
                                    removal.clearErrors();
                                "
                            />
                        </td>
                        <td>
                            <Link
                                v-if="player.player_id && can('Player List')"
                                :href="playerShow(player.player_id)"
                                >{{ player.name }}</Link
                            ><span v-else>{{
                                player.name ||
                                `Unresolved legacy player #${player.legacy_player_id}`
                            }}</span>
                        </td>
                        <td>{{ player.mobile_number || '—' }}</td>
                        <td>{{ player.kyc_status || 'Identity pending' }}</td>
                        <td>{{ player.status || '—' }}</td>
                        <td>{{ playerMoney(player.commission) }}%</td>
                        <td class="text-no-wrap">{{ playerDate(player.created_at) }}</td>
                    </tr>
                    <tr v-if="!players.data.length">
                        <td colspan="7" class="text-center pa-8">No players match your filters.</td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="players" selectable />
        </v-card>
        <AgentForm v-model="editor" :account="account" :outlets="outlets" />
        <v-dialog v-model="assigning" max-width="600" :persistent="assignment.processing">
            <v-card title="Add player">
                <v-card-text>
                    <p class="mb-4">
                        Search by name or mobile number. Active players without an agent can be
                        assigned.
                    </p>
                    <form class="d-flex ga-2" @submit.prevent="find">
                        <v-text-field
                            v-model="lookup"
                            label="Player name or mobile number"
                            :disabled="finding"
                            :error-messages="lookupError"
                        /><v-btn
                            type="submit"
                            color="primary"
                            :loading="finding"
                            :disabled="lookup.trim().length < 3"
                            >Search</v-btn
                        >
                    </form>
                    <v-radio-group
                        v-model="assignment.player_id"
                        :error-messages="assignment.errors.player_id"
                        ><v-radio
                            v-for="player in matches"
                            :key="player.id"
                            :value="player.id"
                            :label="`${player.name} · ${player.mobile_number}`"
                    /></v-radio-group>
                    <p v-if="searched && !matches.length" role="status">
                        No available players found.
                    </p>
                    <v-alert v-if="assignmentError" type="error">{{ assignmentError }}</v-alert>
                </v-card-text>
                <v-card-actions
                    ><v-spacer /><v-btn :disabled="assignment.processing" @click="assigning = false"
                        >Cancel</v-btn
                    ><v-btn
                        color="primary"
                        :disabled="!assignment.player_id"
                        :loading="assignment.processing"
                        @click="addPlayer"
                        >Add player</v-btn
                    ></v-card-actions
                >
            </v-card>
        </v-dialog>
        <v-dialog v-model="resetting" max-width="480" :persistent="password.processing">
            <v-card title="Reset agent password"
                ><v-card-text>
                    <v-alert v-if="passwordError" type="error" class="mb-4">{{
                        passwordError
                    }}</v-alert>
                    <form id="agent-password" @submit.prevent="changePassword">
                        <v-text-field
                            v-model="password.password"
                            label="New password"
                            type="password"
                            autocomplete="new-password"
                            :error-messages="password.errors.password"
                        /><v-text-field
                            v-model="password.password_confirmation"
                            label="Confirm new password"
                            type="password"
                            autocomplete="new-password"
                        />
                    </form> </v-card-text
                ><v-card-actions
                    ><v-spacer /><v-btn :disabled="password.processing" @click="resetting = false"
                        >Cancel</v-btn
                    ><v-btn
                        type="submit"
                        form="agent-password"
                        color="primary"
                        :loading="password.processing"
                        >Reset password</v-btn
                    ></v-card-actions
                ></v-card
            >
        </v-dialog>
        <v-dialog
            :model-value="!!removing"
            max-width="480"
            :persistent="removal.processing"
            @update:model-value="
                (value) => {
                    if (!value) removing = undefined;
                }
            "
        >
            <v-card title="Remove player from agent?"
                ><v-card-text
                    >{{ removing?.name || 'This player' }} will be removed from
                    {{ agentName(account) }}. The player account and wallet will remain
                    available.<v-alert v-if="removalError" type="error" class="mt-4">{{
                        removalError
                    }}</v-alert></v-card-text
                ><v-card-actions
                    ><v-spacer /><v-btn :disabled="removal.processing" @click="removing = undefined"
                        >Cancel</v-btn
                    ><v-btn color="error" :loading="removal.processing" @click="removePlayer"
                        >Remove player</v-btn
                    ></v-card-actions
                ></v-card
            >
        </v-dialog>
    </AgentShell>
</template>
