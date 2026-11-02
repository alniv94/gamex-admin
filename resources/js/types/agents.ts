export interface AgentFilters {
    search: string;
    status: string;
    type: string;
    date_from: string;
    date_to: string;
    sort: string;
    direction: 'asc' | 'desc';
    per_page: number;
    outlet_id?: number | null;
    agent_id?: number | null;
}

export interface AgentRow {
    id: number;
    outlet_id: number | null;
    outlet_name: string | null;
    first_name: string | null;
    middle_name: string | null;
    last_name: string | null;
    mobile_number: string | null;
    email_address: string | null;
    username: string;
    username_conflicts: number;
    commission: string;
    wallet: string;
    type: string;
    sub_type: string | null;
    page_name: string | null;
    is_active: string;
    player_count: number;
    verified_count: number;
    created_at: string | null;
}

export interface AgentPlayer {
    id: number;
    player_id: number | null;
    legacy_player_id: number | null;
    name: string | null;
    mobile_number: string | null;
    status: string | null;
    kyc_status: string | null;
    commission: string;
    created_at: string | null;
}

export interface AgentCashin {
    id: number;
    agent_id: number;
    first_name: string | null;
    middle_name: string | null;
    last_name: string | null;
    agent_type: string;
    player_id: number | null;
    player_name: string | null;
    payment_method: string | null;
    receiver_account: string | null;
    receipt_url: string | null;
    reference_number: string | null;
    amount: string;
    type: string;
    notes: string | null;
    remarks: string | null;
    status: string;
    created_at: string | null;
    evaluated_at: string | null;
    evaluated_by: string | null;
}

export function agentName(
    agent: Pick<AgentRow, 'first_name' | 'middle_name' | 'last_name'>,
): string {
    return (
        [agent.first_name, agent.middle_name, agent.last_name].filter(Boolean).join(' ') ||
        'Unnamed agent'
    );
}
