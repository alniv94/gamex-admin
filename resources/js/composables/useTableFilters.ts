import { computed, toValue } from 'vue';
import type { MaybeRefOrGetter } from 'vue';

type FilterValue = string | number | boolean | null;
export type TableFilterChanges = Record<string, FilterValue>;
export interface TableFilterField {
    key: string;
    label?: string;
    keys?: string[];
    resetValue?: FilterValue;
    defaultValue?: FilterValue;
    ignore?: FilterValue[];
    removable?: boolean;
    options?: { title: string; value: string | number }[];
    format?: 'date-range';
    dependsOn?: string[];
}
export interface ActiveTableFilter {
    key: string;
    label: string;
    value: string;
    removable: boolean;
}
type FilterField = string | TableFilterField;
const defaults: Record<string, Partial<TableFilterField>> = {
    search: { label: 'Search' },
    status: { label: 'Status', resetValue: 'All' },
    filter: { label: 'Status', resetValue: 'All' },
    type: { label: 'Type', resetValue: 'All' },
    account_verified: { label: 'Verification', resetValue: 'All' },
    date_range: { label: 'Date', keys: ['date_from', 'date_to'], format: 'date-range' },
    activity_search: { label: 'Search' },
    activity_dates: {
        label: 'Date',
        keys: ['activity_date_from', 'activity_date_to'],
        format: 'date-range',
    },
    agent_id: { label: 'Agent', resetValue: null },
    outlet_id: { label: 'Outlet', resetValue: null },
    league_id: { label: 'League', resetValue: null },
    team_id: { label: 'Team', resetValue: null },
    game_id: { label: 'Game', resetValue: null },
    player_id: { label: 'Player', resetValue: null },
    card_id: { label: 'Card', resetValue: null },
    duplicate_of: { label: 'Duplicates of player' },
    bet_type_id: {
        label: 'Bet type',
        resetValue: null,
        options: [
            { title: 'Traditional', value: 1 },
            { title: 'Per Quarter', value: 2 },
        ],
    },
    cashier_status: { label: 'Approval status', resetValue: 'All' },
    period: {
        label: 'Period',
        resetValue: 'daily',
        ignore: ['daily'],
        options: [
            { title: 'Weekly', value: 'weekly' },
            { title: 'Monthly', value: 'monthly' },
            { title: 'Yearly', value: 'yearly' },
        ],
    },
    filter_by: {
        label: 'Date basis',
        resetValue: 'Bet Date',
        ignore: ['Bet Date'],
        dependsOn: ['date_from', 'date_to'],
    },
    link_type: { label: 'Link type', resetValue: 'New', ignore: ['New'] },
};

function resolveFields(fields: readonly FilterField[]): TableFilterField[] {
    return fields.map((field) => {
        const definition = typeof field === 'string' ? { key: field } : field;
        return { resetValue: '', ...defaults[definition.key], ...definition };
    });
}
function filterValue(value: unknown): FilterValue {
    return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
        ? value
        : null;
}
function dateLabel(value: unknown): string {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return '';
    const date = new Date(`${value}T00:00:00Z`);
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat('en-PH', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              timeZone: 'UTC',
          }).format(date);
}

export function buildActiveTableFilters(
    values: object,
    fields: readonly FilterField[],
): ActiveTableFilter[] {
    const applied = values as Record<string, unknown>;
    return resolveFields(fields).flatMap((field) => {
        const keys = field.keys ?? [field.key];
        if (field.dependsOn && !field.dependsOn.some((key) => applied[key])) return [];
        let value: string;
        if (field.format === 'date-range') {
            const from = dateLabel(applied[keys[0]!]);
            const to = dateLabel(applied[keys[1]!]);
            if (!from && !to) return [];
            value =
                from && to
                    ? from === to
                        ? from
                        : `${from} – ${to}`
                    : from
                      ? `From ${from}`
                      : `Until ${to}`;
        } else {
            const raw = filterValue(applied[field.key] ?? field.defaultValue);
            if (
                raw === null ||
                raw === '' ||
                raw === 'All' ||
                raw === 'All dates' ||
                field.ignore?.includes(raw)
            )
                return [];
            value =
                field.options?.find((option) => String(option.value) === String(raw))?.title ??
                (field.key.endsWith('_id') ? `#${String(raw)}` : String(raw));
        }
        return [
            {
                key: field.key,
                label: field.label ?? field.key.replaceAll('_', ' '),
                value,
                removable: field.removable !== false,
            },
        ];
    });
}

export function useTableFilters(
    values: MaybeRefOrGetter<object>,
    fields: MaybeRefOrGetter<readonly FilterField[]>,
    onChange: (changes: TableFilterChanges) => void,
    onReset?: () => void,
) {
    const definitions = computed(() => resolveFields(toValue(fields)));
    const activeFilters = computed(() => buildActiveTableFilters(toValue(values), toValue(fields)));
    function state(reset = false): TableFilterChanges {
        const applied = toValue(values) as Record<string, unknown>;
        return Object.fromEntries(
            definitions.value.flatMap((field) =>
                (field.keys ?? [field.key]).map((key) => [
                    key,
                    reset
                        ? (field.resetValue ?? null)
                        : filterValue(applied[key] ?? field.defaultValue ?? field.resetValue),
                ]),
            ),
        );
    }
    function removeFilter(key: string) {
        const field = definitions.value.find((definition) => definition.key === key);
        if (!field || field.removable === false) return;
        const changes = state();
        for (const name of field.keys ?? [field.key]) changes[name] = field.resetValue ?? null;
        onChange(changes);
    }
    function resetTableFilters() {
        if (onReset) onReset();
        else onChange(state(true));
    }
    return { activeFilters, removeFilter, resetTableFilters };
}
