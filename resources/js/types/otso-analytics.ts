export interface PromoFilters {
    date_from: string;
    date_to: string;
    device_type: string | null;
    dialog_step: number | null;
    source_route: string | null;
}

export interface PromoEngagement {
    impressions: number;
    exposed_players: number;
    clicking_players: number;
    played: number;
    closed: number;
    interrupted: number;
    arrived: number;
    tutorial_opened: number;
    submitted: number;
    unknown: number;
    ctr: number | null;
    close_rate: number | null;
    frequency: number | null;
    average_foreground_ms: number | null;
}

export interface PromoTutorial {
    source: string;
    opened: number;
    completed: number;
    dismissed: number;
    interrupted: number;
    completion_rate: number | null;
    slides: number[];
}

export interface PromoRetention {
    cohort_date: string;
    day: number;
    cohort_players: number;
    eligible_players: number;
    portal_returned: number;
    otso_returned: number;
    portal_rate: number | null;
    otso_rate: number | null;
}

export interface PromoReport {
    summary: PromoEngagement;
    daily: (PromoEngagement & { date: string })[];
    steps: (PromoEngagement & { dialog_step: number })[];
    segments: (PromoEngagement & {
        dialog_step: number;
        device_type: string;
        source_route: string | null;
    })[];
    conversion: {
        bets: number;
        players: number;
        first_time_bettors: number;
        gross_amount: number;
        refunded_amount: number;
    } | null;
    tutorials: PromoTutorial[];
    retention: PromoRetention[];
    diagnostics: {
        event_name: string;
        source: string;
        reason: string | null;
        http_status: number | null;
        events: number;
        players: number;
    }[];
    quality: {
        events: number;
        last_received_at: string | null;
        last_occurred_at: string | null;
        average_delay_seconds: number | null;
        delayed_events: number;
        clock_skew_events: number;
    };
    generated_at: string;
    attribution_pending: boolean;
}
