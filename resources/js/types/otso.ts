export function displayOtsoDate(value: string | null) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
        timeZone: 'Asia/Manila',
    })
        .format(new Date(value))
        .replace(/(\d{4}), /, '$1 ');
}

export const quarters = [
    {
        number: 1,
        label: '1st Quarter',
        combination: 'first_quarter_combination',
        result: 'first_quarter_result',
        status: 'first_quarter_status',
    },
    {
        number: 2,
        label: '2nd Quarter',
        combination: 'second_quarter_combination',
        result: 'second_quarter_result',
        status: 'second_quarter_status',
    },
    {
        number: 3,
        label: '3rd Quarter',
        combination: 'third_quarter_combination',
        result: 'third_quarter_result',
        status: 'third_quarter_status',
    },
    {
        number: 4,
        label: '4th Quarter',
        combination: 'fourth_quarter_combination',
        result: 'fourth_quarter_result',
        status: 'fourth_quarter_status',
    },
] as const;
export const prizeFields = [
    { key: 'bet_amount', label: 'Bet Amount' },
    { key: 'jackpot_prize', label: 'Jackpot Prize' },
    { key: 'second_prize', label: 'Second Prize' },
    { key: 'third_prize', label: 'Third Prize' },
    { key: 'fourth_prize', label: 'Fourth Prize' },
    { key: 'pool_percentage', label: 'Pool Percentage' },
] as const;
export interface OtsoConfig {
    id: number;
    legacy_id: number | null;
    name: string;
    logo: string | null;
    bet_amount: string;
    jackpot_prize: string;
    second_prize: string;
    third_prize: string;
    fourth_prize: string;
    pool_percentage: string;
    status: string;
    created_at: string | null;
    updated_at: string | null;
}
export interface OtsoGame extends Omit<OtsoConfig, 'status' | 'name'> {
    ending_game_id: number;
    game_config_id: number | null;
    game_config_name: string | null;
    league: string;
    team_a_name: string;
    team_b_name: string;
    team_a_alias: string | null;
    team_b_alias: string | null;
    team_a_logo: string | null;
    team_b_logo: string | null;
    schedule: string | null;
    betting_deadline: string | null;
    betting: string;
    game_status_id: number;
    game_status: string;
    winners_generated_at: string | null;
    cancellation_reason: string | null;
    cancelled_at: string | null;
    enabled_betting_at: string | null;
    disabled_betting_at: string | null;
}
export interface AvailableGame {
    id: number;
    team_a_name: string;
    team_b_name: string;
    league: string;
    schedule: string;
}
export interface OtsoResult {
    id: number;
    game_id: number;
    version: number;
    status: string;
    first_quarter_result: string | null;
    second_quarter_result: string | null;
    third_quarter_result: string | null;
    fourth_quarter_result: string | null;
    created_at: string | null;
    approved_at: string | null;
}
export interface OtsoBet extends Pick<
    OtsoResult,
    | 'first_quarter_result'
    | 'second_quarter_result'
    | 'third_quarter_result'
    | 'fourth_quarter_result'
> {
    id: number;
    legacy_id: number | null;
    game_id: number;
    ending_game_id: number;
    player_id: number | null;
    name: string | null;
    username: string | null;
    player_name: string | null;
    bet_type: string | null;
    amount: string;
    refunded: string;
    first_quarter_combination: string | null;
    second_quarter_combination: string | null;
    third_quarter_combination: string | null;
    fourth_quarter_combination: string | null;
    first_quarter_status: string | null;
    second_quarter_status: string | null;
    third_quarter_status: string | null;
    fourth_quarter_status: string | null;
    team_a_name: string;
    team_b_name: string;
    team_a_alias: string | null;
    team_b_alias: string | null;
    league: string;
    schedule: string | null;
    created_at: string | null;
    prize_amount: string | null;
    winner_status: string | null;
}
export interface OtsoWinner extends Pick<
    OtsoBet,
    | 'player_id'
    | 'username'
    | 'player_name'
    | 'first_quarter_combination'
    | 'second_quarter_combination'
    | 'third_quarter_combination'
    | 'fourth_quarter_combination'
> {
    id: number;
    bet_id: number;
    ticket_name: string | null;
    winner_type: string;
    winner_type_id: number;
    prize_amount: string;
    winner_status: string;
    prize_released: string;
}
