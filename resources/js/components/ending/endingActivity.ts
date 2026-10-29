import type { EndingLog } from '../../types/ending';
import { betType, displayDate, money } from '../../types/ending';

type Snapshot = Record<string, unknown>;
const labels: Record<string, string> = {
    team_a: 'Team A',
    team_b: 'Team B',
    team_a_name: 'Team A',
    team_b_name: 'Team B',
    game_cards: 'Total cards in game',
    cards_added: 'Cards added',
    bet_type_id: 'Bet type',
    bet_amount_id: 'Bet amount ID',
    card_ids: 'Card IDs',
    quarter_id: 'Quarter',
    game_status_id: 'Game status',
    winner_status_id: 'Winner status',
    config_id: 'Preset ID',
    is_cash: 'Prize type',
    cash_prize: 'Cash prize',
    other_prize: 'Non-cash prize',
};
const omitted = new Set(['presets', 'prizes', 'legacy_checksum', 'legacy_dates']);

function read(value: unknown): unknown {
    if (typeof value !== 'string') return value;
    try {
        return JSON.parse(value);
    } catch {
        return value;
    }
}
function record(value: unknown): Snapshot | null {
    const parsed = read(value);
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
        ? (parsed as Snapshot)
        : null;
}
function fieldLabel(field: string): string {
    return (
        labels[field] || field.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase())
    );
}
function format(value: unknown, field = ''): string {
    if (value === null && field === 'game_status_id') return 'Scheduled';
    if (value === null || value === undefined || value === '') return '—';
    if (Array.isArray(value))
        return value.length ? value.map((item) => format(item, field)).join(', ') : 'None';
    if (typeof value === 'object')
        return Object.entries(value)
            .map(([key, item]) => `${fieldLabel(key)}: ${format(item, key)}`)
            .join(' · ');
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value !== 'string' && typeof value !== 'number' && typeof value !== 'bigint')
        return '—';
    const text = String(value);
    if (field === 'bet_type_id')
        return [1, 2].includes(Number(value)) ? betType(Number(value)) : text;
    if (field === 'winner_status_id')
        return ({ 1: 'Verified', 2: 'Approved' } as Record<number, string>)[Number(value)] || text;
    if (field === 'game_status_id')
        return (
            (
                {
                    1: 'Started',
                    2: 'End of 1st Quarter',
                    3: 'End of 2nd Quarter',
                    4: 'End of 3rd Quarter',
                    5: 'Finished',
                } as Record<number, string>
            )[Number(value)] || text
        );
    if (field === 'amount' && Number.isFinite(Number(value))) return `PHP ${money(Number(value))}`;
    if (field === 'cash_prize' && Number.isFinite(Number(value)))
        return `${money(Number(value))} tokens`;
    if ((field === 'schedule' || field.endsWith('_at')) && typeof value === 'string') {
        try {
            return displayDate(value);
        } catch {
            return value;
        }
    }
    return text;
}
function flatten(
    snapshot: Snapshot | null,
    prefix = '',
): Record<string, { field: string; label: string; value: unknown }> {
    const fields: ReturnType<typeof flatten> = {};
    for (const [field, value] of Object.entries(snapshot || {})) {
        if (omitted.has(field)) continue;
        if (
            (field === 'team_a' && snapshot?.team_a_name) ||
            (field === 'team_b' && snapshot?.team_b_name)
        )
            continue;
        const key = prefix ? `${prefix}.${field}` : field;
        if (value && typeof value === 'object' && !Array.isArray(value))
            Object.assign(fields, flatten(record(value), key));
        else
            fields[key] = {
                field,
                label: prefix ? `${fieldLabel(prefix)} · ${fieldLabel(field)}` : fieldLabel(field),
                value,
            };
    }
    return fields;
}
function prizes(value: unknown) {
    return (Array.isArray(value) ? value : []).map((item, index) => {
        const prize = record(item) || {};
        return {
            quarter: `Q${format(prize.quarter_id ?? index + 1)}`,
            value:
                prize.is_cash === 'Not Cash'
                    ? format(prize.other_prize)
                    : format(prize.cash_prize, 'cash_prize'),
        };
    });
}
function same(a: unknown, b: unknown): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

export function endingActivity(log: EndingLog) {
    const legacy = record(log.details);
    let before = record(log.before ?? legacy?.before);
    const after = record(
        log.after ?? legacy?.after ?? (legacy && !('before' in legacy) ? legacy : null),
    );
    if (before?.winner_status_id && after?.status && !before.status)
        before = { ...before, status: format(before.winner_status_id, 'winner_status_id') };
    const beforeFields = flatten(before);
    const afterFields = flatten(after);
    const created = log.action.startsWith('create_') || log.action === 'verify_winner';
    const changes = Object.entries(after ? afterFields : beforeFields)
        .filter(([key, entry]) => !before || !after || !same(beforeFields[key]?.value, entry.value))
        .map(([key, entry]) => ({
            field: key,
            label:
                entry.field === 'cards'
                    ? log.action === 'add_card'
                        ? 'Cards at this bet amount'
                        : 'Card count'
                    : entry.field === 'quantity' && log.action === 'add_card'
                      ? 'Cards added'
                      : entry.label,
            before: before
                ? format(beforeFields[key]?.value, entry.field)
                : created
                  ? '—'
                  : 'Not recorded',
            after: after ? format(afterFields[key]?.value, entry.field) : 'Removed',
        }));
    let note = '';
    if (!before && after) {
        note = created
            ? 'This record was created by this action, so there was no previous state.'
            : 'Previous values were not recorded at the time. They cannot be recovered from current preset settings.';
        if (log.action === 'create_game' && !('presets' in after))
            note += ' The original log did not capture the preset breakdown.';
    }
    const narrative = log.details && !legacy ? format(read(log.details)) : '';
    let summary = narrative
        ? narrative.length > 120
            ? narrative.slice(0, 120) + '…'
            : narrative
        : 'View recorded changes';
    if (log.action === 'create_game' && after?.cards !== undefined)
        summary = `Game created with ${format(after.cards)} cards`;
    if (log.action === 'add_card' && (after?.cards_added ?? after?.quantity) !== undefined) {
        const added = after?.cards_added ?? after?.quantity;
        summary = `${format(added)} ${Number(added) === 1 ? 'card' : 'cards'} added`;
        if (before?.cards !== undefined && after?.cards !== undefined)
            summary += ` · ${format(before.cards)} → ${format(after.cards)} cards`;
    }
    if (after?.betting && before?.betting)
        summary = `${format(before.betting)} → ${format(after.betting)}`;
    if (after?.quarter_id && after.team_a_score !== undefined)
        summary = `Quarter ${format(after.quarter_id)} · ${format(after.team_a_score)}–${format(after.team_b_score)} · ${format(after.combination)}`;
    if (after?.status && log.subject_type === 'winner')
        summary = `Winner ${format(after.status).toLowerCase()}`;
    const subject = log.subject_type || 'record';
    const recordName = fieldLabel(subject);
    const reference = log.subject_id ?? log.reference_id;
    const context =
        after?.bet_type_id && after.amount !== undefined
            ? `${format(after.bet_type_id, 'bet_type_id')} · ${format(after.amount, 'amount')}`
            : '';
    const presets = (Array.isArray(after?.presets) ? after.presets : []).map((item, index) => {
        const preset = record(item) || {};
        return {
            key: `${format(preset.bet_type_id)}-${format(preset.config_id ?? index)}`,
            type: format(preset.bet_type_id, 'bet_type_id'),
            amount: format(preset.amount, 'amount'),
            cards: format(preset.quantity),
            excluded: preset.quantity === 0 || preset.quantity === '0',
            prizes: prizes(preset.prizes),
        };
    });
    const prizeRows = same(before?.prizes, after?.prizes) ? [] : prizes(after?.prizes);
    return {
        log,
        summary,
        context,
        recordName: reference ? `${recordName} #${reference}` : recordName,
        note,
        narrative,
        changes,
        presets,
        prizeRows,
    };
}
