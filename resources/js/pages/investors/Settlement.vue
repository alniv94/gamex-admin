<script setup lang="ts">
import AdminDataTable from '@/components/AdminDataTable.vue';
import type { DataTableHeader } from 'vuetify';
import { index, settle, specialAction } from '@/actions/App/Http/Controllers/InvestorController';
import EndingPager from '@/components/ending/EndingPager.vue';
import InvestorShell from '@/components/investors/InvestorShell.vue';
import { usePermissions } from '@/composables/usePermissions';
import type { PageResult } from '@/types/ending';
import type { Investor } from '@/types/investors';
import { investorMoney, investorName } from '@/types/investors';
import { playerDate } from '@/types/players';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Item {
    id: number;
    investor_id: number;
    player_id: number | null;
    legacy_player_id: number | null;
    ledger?: string;
    amount?: string;
    commission_amount?: string;
    commission_percent?: string;
    bet_id?: number;
    created_at?: string | null;
    bet_created_at?: string | null;
}
interface Special {
    id: number;
    amount: string;
    status: string;
    mode_of_payment: string | null;
    reference: string | null;
    remarks: string | null;
}
const props = defineProps<{
    kind: string;
    account: Investor | null;
    filters: Record<string, string | number | null>;
    items: PageResult<Item>;
    total: string;
    special?: Special;
}>();
const { can } = usePermissions();
const confirmOpen = ref(false);
const action = ref<'verify' | 'approve' | 'settle'>('settle');
const title = computed(() =>
    props.kind === 'special'
        ? 'Special Commission Settlement'
        : props.kind === 'payouts'
          ? 'Payouts Settlement'
          : 'Commission Settlement',
);
const form = useForm({
    mode_of_payment: '',
    reference: '',
    remarks: '',
    date_from: String(props.filters.date_from ?? ''),
    date_to: String(props.filters.date_to ?? ''),
});
function confirm(value: typeof action.value) {
    action.value = value;
    form.clearErrors();
    confirmOpen.value = true;
}
function submit() {
    if (props.special) {
        form.post(specialAction.url({ settlement: props.special.id, action: action.value }), {
            preserveScroll: true,
            onSuccess: () => (confirmOpen.value = false),
        });
    } else if (props.account) {
        form.post(settle.url({ kind: props.kind, investor: props.account.id }), {
            onSuccess: () => (confirmOpen.value = false),
        });
    }
}
function printReceipt() {
    window.print();
}

const tableHeaders: DataTableHeader[] = [
    { title: 'Item', key: 'id', sortable: true },
    { title: 'Ledger', key: 'ledger', sortable: true },
    { title: 'Agent ID', key: 'investor_id', sortable: true },
    { title: 'Player ID', key: 'player_id', sortable: true },
    { title: 'Bet ID', key: 'bet_id', sortable: true },
    { title: 'Commission %', key: 'commission_percent', sortable: true },
    { title: 'Amount', key: 'amount', sortable: true },
    { title: 'Date', key: 'created_at', sortable: true },
];
</script>

<template>
    <InvestorShell :title="title">
        <div class="d-flex align-center flex-wrap ga-3 mb-4">
            <Link
                :href="index(kind === 'special' ? 'special-commissions' : kind)"
                class="text-primary"
                >Back to {{ title }}</Link
            >
            <v-spacer /><v-btn prepend-icon="mdi-printer" variant="outlined" @click="printReceipt"
                >Print</v-btn
            >
        </div>
        <v-card class="mb-4">
            <v-card-text>
                <h2 class="text-h6">{{ account ? investorName(account) : 'Unknown investor' }}</h2>
                <p v-if="form.date_from || form.date_to" class="my-2">
                    {{ form.date_from || 'All earlier dates' }} — {{ form.date_to || 'Latest' }}
                </p>
                <p class="text-h5 my-3">{{ investorMoney(total) }}</p>
                <v-chip v-if="special">{{ special.status }}</v-chip>
                <p v-if="special?.reference" class="mt-2">
                    {{ special.mode_of_payment }} · {{ special.reference }}
                </p>
                <div class="mt-4">
                    <template v-if="special">
                        <v-btn
                            v-if="
                                special.status === 'Requested' &&
                                can('Special Commission Settlement Verify')
                            "
                            color="primary"
                            @click="confirm('verify')"
                            >Verify Commission</v-btn
                        >
                        <v-btn
                            v-if="
                                special.status === 'Verified' &&
                                can('Special Commission Settlement Approve')
                            "
                            color="primary"
                            @click="confirm('approve')"
                            >Approve Settlement</v-btn
                        >
                    </template>
                    <v-btn
                        v-else-if="can('Investors')"
                        color="primary"
                        prepend-icon="mdi-cash-check"
                        :disabled="Number(total) <= 0 || account?.status !== 'Active'"
                        @click="confirm('settle')"
                        >Settle {{ items.total }} Items</v-btn
                    >
                </div>
            </v-card-text>
        </v-card>
        <v-card>
            <AdminDataTable
                :headers="tableHeaders"
                :items="items.data"
                server
                :toolbar="true"
                title="Settlement items"
            >
                <template #headers>
                    <tr>
                        <th>Item</th>
                        <th>Ledger</th>
                        <th>Agent ID</th>
                        <th>Player ID</th>
                        <th v-if="kind !== 'payouts'">Bet ID</th>
                        <th v-if="kind !== 'payouts'">Commission %</th>
                        <th>Amount</th>
                        <th>Date</th>
                    </tr>
                </template>
                <template #item="{ item: item }"
                    ><tr :key="(item.ledger || '') + item.id">
                        <td>{{ item.id }}</td>
                        <td>{{ item.ledger || 'Base' }}</td>
                        <td>{{ item.investor_id }}</td>
                        <td>
                            {{
                                item.player_id || 'Legacy #' + (item.legacy_player_id ?? 'unknown')
                            }}
                        </td>
                        <td v-if="kind !== 'payouts'">{{ item.bet_id }}</td>
                        <td v-if="kind !== 'payouts'">{{ item.commission_percent }}%</td>
                        <td>{{ investorMoney(item.amount ?? item.commission_amount) }}</td>
                        <td>{{ playerDate(item.created_at ?? item.bet_created_at) }}</td>
                    </tr></template
                >
                <template #no-data> No unsettled items in this date range. </template>
            </AdminDataTable>
            <EndingPager :page="items" />
        </v-card>
        <v-dialog v-model="confirmOpen" max-width="560" persistent>
            <v-form @submit.prevent="submit">
                <v-card :title="action === 'verify' ? 'Verify Commission' : 'Confirm Settlement'">
                    <v-card-text>
                        <p class="mb-4">
                            {{ action === 'verify' ? 'Verify' : 'Record payment of' }}
                            {{ investorMoney(total) }} for
                            {{ account ? investorName(account) : 'this investor' }}.
                        </p>
                        <v-alert
                            v-if="form.hasErrors"
                            type="error"
                            variant="tonal"
                            class="mb-4"
                            role="alert"
                            >{{ Object.values(form.errors)[0] }}</v-alert
                        >
                        <template v-if="action !== 'verify'">
                            <v-combobox
                                v-model="form.mode_of_payment"
                                label="Mode of payment"
                                :items="['Bank Transfer', 'GCash', 'Cash', 'Cheque']"
                                :error-messages="form.errors.mode_of_payment"
                            />
                            <v-text-field
                                v-model="form.reference"
                                label="Payment reference"
                                :error-messages="form.errors.reference"
                            />
                            <v-textarea
                                v-model="form.remarks"
                                label="Remarks"
                                rows="3"
                                :error-messages="form.errors.remarks"
                            />
                        </template>
                    </v-card-text>
                    <v-card-actions
                        ><v-spacer /><v-btn :disabled="form.processing" @click="confirmOpen = false"
                            >Cancel</v-btn
                        ><v-btn
                            type="submit"
                            color="primary"
                            variant="flat"
                            :loading="form.processing"
                            >Confirm
                            {{ action === 'verify' ? 'Verification' : 'Settlement' }}</v-btn
                        ></v-card-actions
                    >
                </v-card>
            </v-form>
        </v-dialog>
    </InvestorShell>
</template>
