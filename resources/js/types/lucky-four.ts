export interface LuckyFourPrizes {
    id: number;
    jackpot_prize: string | null;
    three_digits_prize: string | null;
    initial_amount: string | null;
    percentage_deduction: string | null;
    bet_amount: string | null;
    amount_added?: string | null;
    created_at?: string | null;
}

export interface LuckyFourBet {
    id: number;
    ending_bet_id: number;
    game_id: number | null;
    combination: string | null;
    amount: string | null;
    is_added: string | null;
    player_id: number | null;
    player_name: string | null;
    created_at: string | null;
    winner_id: number | null;
    winner_status: string | null;
    prize_amount: string | null;
    prize_released: string | null;
    tier?: string | null;
    calculated_prize?: string | null;
}

export interface LuckyFourFilters {
    search?: string;
    status?: string;
    game_id?: number | string;
    date_from?: string;
    date_to?: string;
}

export const prizeFields = [
    { key: 'jackpot_prize', label: 'Jackpot prize' },
    { key: 'three_digits_prize', label: 'Three-digit prize' },
    { key: 'initial_amount', label: 'Initial amount' },
    { key: 'percentage_deduction', label: 'Pool contribution (%)' },
    { key: 'bet_amount', label: 'Bet amount' },
] as const;
