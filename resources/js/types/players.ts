export interface PlayerRecord {
    id: number;
    name: string;
    mobile_number: string | null;
    email_address: string | null;
    account_verified: string;
    active_status: string;
    created_at: string | null;
    verified_at: string | null;
    marital_status: string | null;
    birthdate: string | null;
    nationality: string | null;
    source_of_income: string | null;
    nature_of_work: string | null;
    nature_of_business: string | null;
    address: string | null;
}

export interface PlayerDetails extends PlayerRecord {
    gender: string | null;
    age: number | null;
    birthplace: string | null;
    gaming_venue: string | null;
    current_address: string | null;
    current_barangay: string | null;
    current_city_mun: string | null;
    permanent_barangay: string | null;
    permanent_city_mun: string | null;
    others_income: string | null;
    wallet: string | null;
    admin_deactivate_reason: string | null;
    player_deactivate_reason: string | null;
    rejected_reason: string | null;
    betting: string | null;
    cashin: string | null;
    cashout: string | null;
    selfie: string | null;
    valid_id: string | null;
}

export interface FundAccount {
    id: number;
    bank: string | null;
    type: string | null;
    account_number: string;
    account_name: string | null;
    is_verified: string;
    is_active: string;
    is_default: string;
}

export interface PlayerActivity {
    id: number;
    details: string | null;
    created_at: string | null;
    transaction_type?: string | null;
    amount?: string | null;
    admin?: string | null;
    action?: string;
    origin?: string;
}

export interface NdrpRecord {
    firstName: string;
    middleName: string;
    lastName: string;
    birthdate: string;
    source: string;
    photoPath: string;
}

export interface NdrpResult {
    status: 'ready' | 'unavailable' | 'error';
    message?: string;
    data?: {
        totalNumberOfRecords: number;
        numberOfGelRecords: number;
        numberOfGuestRecords: number;
        numberOfGovtRecords: number;
        records: NdrpRecord[];
    };
}

export function playerDate(value: string | null | undefined, dateOnly = false): string {
    if (!value) return '-';
    const date = new Date(
        dateOnly
            ? `${value.slice(0, 10)}T00:00:00+08:00`
            : value.includes('T')
              ? value
              : `${value.replace(' ', 'T')}Z`,
    );
    if (Number.isNaN(date.getTime())) return '-';
    return new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        ...(dateOnly ? {} : { hour: '2-digit', minute: '2-digit', hour12: true }),
    }).format(date);
}

export function playerMoney(value: string | null | undefined): string {
    return Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}
