<script setup lang="ts">
import {
    index,
    show,
    subAccount,
    refreshReferrals,
} from '@/actions/App/Http/Controllers/InvestorController';
import EndingPager from '@/components/ending/EndingPager.vue';
import InvestorForm from '@/components/investors/InvestorForm.vue';
import InvestorShell from '@/components/investors/InvestorShell.vue';
import type { PageResult } from '@/types/ending';
import type { Investor, Outlet } from '@/types/investors';
import { investorName, investorMoney, investorRole } from '@/types/investors';
import { playerDate } from '@/types/players';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

interface SubAccount {
    id: number;
    first_name: string;
    last_name: string;
    mobile_number: string;
    status: string;
    created_at: string | null;
}
interface AgentPlayer {
    id: number;
    player_id: number | null;
    legacy_player_id: number | null;
    name: string | null;
    commission: string;
    investor_sub_type_id: number;
}
interface Settlement {
    id: number;
    amount: string;
    mode_of_payment: string;
    reference: string;
    remarks: string | null;
    processed_at: string;
    kind: string;
}
const props = defineProps<{
    account: Investor;
    children: Investor[];
    players: PageResult<AgentPlayer>;
    subAccounts: SubAccount[];
    outlets: Outlet[];
    settlements: PageResult<Settlement>;
}>();
const page = usePage();
const editOpen = ref(false);
const subOpen = ref(false);
const editingSub = ref<number | undefined>();
const form = useForm({
    first_name: '',
    last_name: '',
    mobile_number: '',
    password: '',
    status: 'Active',
});
function editSub(account?: SubAccount) {
    form.reset();
    form.clearErrors();
    editingSub.value = account?.id;
    if (account)
        Object.assign(form, {
            first_name: account.first_name,
            last_name: account.last_name,
            mobile_number: account.mobile_number,
            status: account.status,
        });
    subOpen.value = true;
}
function saveSub() {
    form.post(subAccount.url({ investor: props.account.id, account: editingSub.value }), {
        preserveScroll: true,
        onSuccess: () => {
            subOpen.value = false;
            form.reset('password');
        },
    });
}
</script>

<template>
    <InvestorShell :title="investorName(account)">
        <div class="d-flex flex-wrap ga-2 mb-4">
            <Link :href="index('list')" class="text-primary align-self-center mr-3"
                >Investor List</Link
            >
            <v-chip>{{ investorRole(account.investor_type_id) }}</v-chip>
            <v-chip :color="account.status === 'Active' ? 'success' : 'error'">{{
                account.status
            }}</v-chip>
            <v-spacer />
            <v-btn prepend-icon="mdi-pencil" color="primary" @click="editOpen = true"
                >Edit Account</v-btn
            >
            <v-btn
                variant="outlined"
                prepend-icon="mdi-link"
                @click="router.post(refreshReferrals(account.id), {}, { preserveScroll: true })"
                >Update Referral Links</v-btn
            >
        </div>
        <v-alert
            v-if="Object.keys(page.props.errors).length && !subOpen && !editOpen"
            type="error"
            variant="tonal"
            class="mb-4"
            role="alert"
            >{{ Object.values(page.props.errors)[0] }}</v-alert
        >
        <v-card class="mb-5">
            <v-card-text>
                <v-row>
                    <v-col cols="12" md="3"
                        ><div class="text-medium-emphasis">Territory</div>
                        {{ account.territory || '—' }}</v-col
                    >
                    <v-col cols="12" md="3"
                        ><div class="text-medium-emphasis">Mobile</div>
                        +63{{ account.mobile_number }}</v-col
                    >
                    <v-col cols="12" md="3"
                        ><div class="text-medium-emphasis">Wallet</div>
                        {{ investorMoney(account.wallet) }}</v-col
                    >
                    <v-col cols="12" md="3"
                        ><div class="text-medium-emphasis">Outlet</div>
                        {{ account.outlet_name || '—' }}</v-col
                    >
                    <v-col cols="12" md="3"
                        ><div class="text-medium-emphasis">Base commission</div>
                        {{ account.base_commission }}%</v-col
                    >
                    <v-col cols="12" md="3"
                        ><div class="text-medium-emphasis">Commission / sub commission</div>
                        {{ account.commission }}% / {{ account.sub_commission }}%</v-col
                    >
                    <v-col cols="12" md="3"
                        ><div class="text-medium-emphasis">Category / link type</div>
                        {{ account.category }} / {{ account.link_type }}</v-col
                    >
                    <v-col cols="12" md="3"
                        ><div class="text-medium-emphasis">Created</div>
                        {{ playerDate(account.created_at) }}</v-col
                    >
                </v-row>
            </v-card-text>
        </v-card>
        <v-card title="Agent Hierarchy" class="mb-5">
            <v-table class="players-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Mobile number</th>
                        <th>Commission</th>
                        <th>Wallet</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="child in children" :key="child.id">
                        <td>
                            <Link :href="show(child.id)">{{ investorName(child) }}</Link>
                        </td>
                        <td>{{ investorRole(child.investor_type_id) }}</td>
                        <td>+63{{ child.mobile_number }}</td>
                        <td>{{ child.commission }}%</td>
                        <td>{{ investorMoney(child.wallet) }}</td>
                        <td>{{ child.status }}</td>
                    </tr>
                    <tr v-if="!children.length">
                        <td colspan="6" class="pa-6 text-center">No direct agents.</td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>
        <v-card title="Players" class="mb-5">
            <v-table class="players-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Commission</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="player in players.data" :key="player.id">
                        <td>{{ player.name || 'Legacy player #' + player.legacy_player_id }}</td>
                        <td>
                            {{
                                player.investor_sub_type_id === 1
                                    ? 'Sales Representative'
                                    : 'Regular Player'
                            }}
                        </td>
                        <td>{{ player.commission }}%</td>
                    </tr>
                    <tr v-if="!players.data.length">
                        <td colspan="3" class="pa-6 text-center">No direct players.</td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="players" />
        </v-card>
        <v-card class="mb-5">
            <v-card-title class="d-flex align-center"
                >Sub-accounts<v-spacer /><v-btn
                    size="small"
                    color="primary"
                    prepend-icon="mdi-plus"
                    @click="editSub()"
                    >Add Sub-account</v-btn
                ></v-card-title
            >
            <v-table class="players-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Mobile number</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="sub in subAccounts" :key="sub.id">
                        <td>{{ investorName(sub) }}</td>
                        <td>+63{{ sub.mobile_number }}</td>
                        <td>{{ sub.status }}</td>
                        <td>{{ playerDate(sub.created_at) }}</td>
                        <td>
                            <v-btn
                                icon="mdi-pencil"
                                variant="text"
                                aria-label="Edit sub-account"
                                @click="editSub(sub)"
                            />
                        </td>
                    </tr>
                    <tr v-if="!subAccounts.length">
                        <td colspan="5" class="pa-6 text-center">No sub-accounts.</td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>
        <v-card title="Settlement History">
            <v-table class="players-table">
                <thead>
                    <tr>
                        <th>Settlement</th>
                        <th>Amount</th>
                        <th>Payment method</th>
                        <th>Reference</th>
                        <th>Remarks</th>
                        <th>Processed</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in settlements.data" :key="entry.kind + entry.id">
                        <td>
                            {{ entry.kind === 'Payout' ? 'PS' : 'CS'
                            }}{{ String(entry.id).padStart(8, '0') }}
                        </td>
                        <td>{{ investorMoney(entry.amount) }}</td>
                        <td>{{ entry.mode_of_payment }}</td>
                        <td>{{ entry.reference }}</td>
                        <td>{{ entry.remarks || '—' }}</td>
                        <td>{{ playerDate(entry.processed_at) }}</td>
                    </tr>
                    <tr v-if="!settlements.data.length">
                        <td colspan="6" class="pa-6 text-center">No settlements recorded.</td>
                    </tr>
                </tbody>
            </v-table>
            <EndingPager :page="settlements" />
        </v-card>
        <v-dialog v-model="editOpen" max-width="820" scrollable>
            <InvestorForm
                v-if="editOpen"
                :account="account"
                :outlets="outlets"
                @saved="editOpen = false"
                @cancel="editOpen = false"
            />
        </v-dialog>
        <v-dialog v-model="subOpen" max-width="600">
            <v-form @submit.prevent="saveSub">
                <v-card :title="editingSub ? 'Edit Sub-account' : 'New Sub-account'">
                    <v-card-text>
                        <v-alert
                            v-if="form.hasErrors"
                            type="error"
                            variant="tonal"
                            class="mb-4"
                            role="alert"
                            >{{ Object.values(form.errors)[0] }}</v-alert
                        >
                        <v-text-field
                            v-model="form.first_name"
                            label="First name"
                            :error-messages="form.errors.first_name"
                        />
                        <v-text-field
                            v-model="form.last_name"
                            label="Last name"
                            :error-messages="form.errors.last_name"
                        />
                        <v-text-field
                            v-model="form.mobile_number"
                            label="Mobile number"
                            prefix="+63"
                            maxlength="10"
                            :error-messages="form.errors.mobile_number"
                        />
                        <v-text-field
                            v-model="form.password"
                            :label="editingSub ? 'New password (optional)' : 'Password'"
                            type="password"
                            autocomplete="new-password"
                            :error-messages="form.errors.password"
                        />
                        <v-select
                            v-model="form.status"
                            label="Status"
                            :items="['Active', 'Inactive']"
                            :error-messages="form.errors.status"
                        />
                    </v-card-text>
                    <v-card-actions
                        ><v-spacer /><v-btn @click="subOpen = false">Cancel</v-btn
                        ><v-btn
                            color="primary"
                            variant="flat"
                            type="submit"
                            :loading="form.processing"
                            >Save Sub-account</v-btn
                        ></v-card-actions
                    >
                </v-card>
            </v-form>
        </v-dialog>
    </InvestorShell>
</template>
