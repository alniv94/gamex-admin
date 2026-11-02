export interface Investor {
    id: number;
    outlet_id: number | null;
    outlet_name: string | null;
    investor_type_id: number;
    territory: string | null;
    username: string | null;
    first_name: string | null;
    last_name: string | null;
    mobile_number: string | null;
    base_commission: string;
    commission: string;
    sub_commission: string;
    wallet: string;
    link_type: string;
    category: string;
    status: string;
    created_at: string | null;
}
export interface InvestorRow extends Investor {
    name?: string;
    investor_id?: number;
    amount?: string;
    min_date?: string | null;
    max_date?: string | null;
    bet_count?: number;
    bet_amount?: string;
    sales_manager_count?: number;
    regular_player_count?: number;
    total_accounts?: number;
    items?: number;
    process_date?: string | null;
    referral_type?: string;
    link_for?: string;
    url?: string | null;
}
export interface InvestorSubAccount {
    id: number;
    first_name: string;
    last_name: string;
    mobile_number: string;
    status: string;
    created_at: string | null;
}
export interface Outlet {
    id: number;
    outlet_name: string;
}
export const investorName = (account: { first_name: string | null; last_name: string | null }) =>
    [account.first_name, account.last_name].filter(Boolean).join(' ') || 'Unnamed account';
export const investorRole = (type: number) =>
    ({ 1: 'Master Agent', 2: 'Sales Manager', 3: 'Sales Supervisor' })[type] ?? 'Agent';
export const investorMoney = (value: string | number | null | undefined) =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(
        Number(value ?? 0),
    );
