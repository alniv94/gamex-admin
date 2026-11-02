import type { DataTableHeader } from 'vuetify';

export function tableValue(item: unknown, key: string): unknown {
    return key.split('.').reduce<unknown>((value, part) => {
        if (value && typeof value === 'object' && part in value) {
            return (value as Record<string, unknown>)[part];
        }
        return undefined;
    }, item);
}

export function csvContent(rows: unknown[][]): string {
    return (
        '\uFEFF' +
        rows
            .map((row) =>
                row
                    .map((value) => {
                        let text = '';
                        if (typeof value === 'string') text = value;
                        else if (
                            typeof value === 'number' ||
                            typeof value === 'boolean' ||
                            typeof value === 'bigint'
                        )
                            text = value.toString();
                        else if (value !== null && value !== undefined)
                            text = JSON.stringify(value) ?? '';
                        if (/^[\s]*[=+@-]/.test(text)) text = `'${text}`;
                        return `"${text.replaceAll('"', '""')}"`;
                    })
                    .join(','),
            )
            .join('\r\n')
    );
}

export function exportTableRows(filename: string, rows: unknown[][]): void {
    const url = URL.createObjectURL(
        new Blob([csvContent(rows)], { type: 'text/csv;charset=utf-8;' }),
    );
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.append(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function exportTableItems<T extends object>(
    filename: string,
    items: readonly T[],
    headers: readonly DataTableHeader<T>[],
): void {
    const columns = headers.filter(
        (header) =>
            header.key &&
            !['actions', 'action', 'data-table-select', 'data-table-expand'].includes(header.key),
    );
    exportTableRows(filename, [
        columns.map((column) => column.title ?? column.key),
        ...items.map((item) =>
            columns.map((column) => {
                if (typeof column.value === 'function') return column.value(item);
                return tableValue(
                    item,
                    typeof column.value === 'string' ? column.value : String(column.key),
                );
            }),
        ),
    ]);
}
