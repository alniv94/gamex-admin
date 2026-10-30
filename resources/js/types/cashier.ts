export interface CashierRecord {
    id: number;
    player_id: number | null;
    player_name: string | null;
    mobile_number: string | null;
    bank: string | null;
    wallet: string | null;
    account_name?: string | null;
    account_number: string | null;
    reference_number?: string | null;
    receiver_account?: string | null;
    notes?: string | null;
    receipt_url?: string | null;
    amount: string;
    type: string;
    remarks: string | null;
    status: string;
    created_at: string | null;
    evaluated_at: string | null;
    evaluated_by: string | null;
}

export interface CashierPlayer {
    id: number;
    name: string;
    mobile_number: string;
    wallet: string;
}
