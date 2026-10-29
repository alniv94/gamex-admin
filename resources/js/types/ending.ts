export interface PageResult<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
    per_page: number;
    from: number | null;
    to: number | null;
}
export interface League {
    id: number;
    league: string;
    logo?: string | null;
    is_local?: string;
    is_active?: string;
    created_at?: string | null;
}
export interface Team {
    id: number;
    league_id: number;
    team_name: string;
    team_alias?: string | null;
    logo?: string | null;
    is_active?: string;
    created_at?: string | null;
    league?: League | null;
}
export interface Preset {
    config_id: number;
    bet_type_id: number;
    amount: string;
    quantity: number;
}
export interface EndingGame {
    id: number;
    team_a: number;
    team_b: number;
    team_a_name: string;
    team_b_name: string;
    team_a_logo: string | null;
    team_b_logo: string | null;
    league_id: number | null;
    league: string | null;
    schedule: string | null;
    betting: string;
    game_status_id: number | null;
    status: string;
    is_max: string;
    is_lucky_four: string;
    is_power_ball: string;
    created_at: string | null;
    enabled_betting_at: string | null;
}
export interface GameResult {
    id: number;
    quarter_id: number;
    team_a_score: number;
    team_b_score: number;
    combination: string;
    created_at: string;
}
export interface Winner {
    bet_id: number;
    player_id: number | null;
    legacy_player_id: number | null;
    card_id: number;
    player_name: string | null;
    ticket_name: string | null;
    quarter_id: number;
    combination: string;
    is_cash: string;
    cash_prize: string | null;
    other_prize: string | null;
    winner_id: number | null;
    winner_status_id: number | null;
    prize_released: string | null;
    status: string;
    eligible: number;
}
export interface GameCard {
    id: number;
    bet_amount_id: number;
    betting: string;
    generated_file: string;
    refunded: string;
    amount: string;
    bet_type_id: number;
    bets: number;
}
export interface Bet {
    id: number;
    quarter_id: number;
    name: string | null;
    player_name: string | null;
    player_id: number | null;
    legacy_player_id: number | null;
    combination: string;
    result: string;
    refunded: string;
}
export interface BetAmount {
    id: number;
    amount: string;
    bet_type_id: number;
    cards: number;
    disabled_cards: number;
    generated_cards: number;
    exceeded_cards: number;
}
export interface ConfigRecord {
    id: number;
    config_name: string;
    config_desc: string | null;
    config_value: string;
}
export interface CardPreset {
    id: number;
    bet_type_id: number;
    amount: string;
    card_quantity: number;
    status: string;
}
export interface Prize {
    bet_amount_id: number;
    quarter_id: number;
    is_cash: string;
    cash_prize: string | null;
    other_prize: string | null;
}
export interface EndingLog {
    id: number;
    game_id: number | null;
    user_id: number | null;
    actor_name: string | null;
    action: string;
    subject_type?: string;
    subject_id?: number;
    reference_id?: number;
    before?: string | Record<string, unknown> | null;
    after?: string | Record<string, unknown> | null;
    details?: string | null;
    reason?: string | null;
    created_at: string | null;
}
export function displayDate(value: string | null) {
    if (!value) return '—';
    const date = parseDate(value);
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Manila',
    }).format(date);
}
export function inputDate(value: string | null) {
    if (!value) return '';
    return new Intl.DateTimeFormat('sv-SE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Manila',
        hourCycle: 'h23',
    })
        .format(parseDate(value))
        .replace(' ', 'T');
}
export function money(value: string | number | null) {
    return Number(value || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}
export function betType(type: number) {
    return Number(type) === 1 ? 'Traditional' : 'Per Quarter';
}

function parseDate(value: string): Date {
    const normalized = value.replace(' ', 'T');
    return new Date(normalized + (/(?:Z|[+-]\d{2}:?\d{2})$/.test(normalized) ? '' : 'Z'));
}
