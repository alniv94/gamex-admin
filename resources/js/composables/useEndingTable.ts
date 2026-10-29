import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, toValue } from 'vue';

export function useEndingSearch(url: string, initial = '', perPage: number | (() => number) = 5) {
    const search = ref(initial);
    let timer: ReturnType<typeof setTimeout> | undefined;
    function filter() {
        clearTimeout(timer);
        timer = setTimeout(
            () =>
                router.get(
                    url,
                    { search: search.value || '', per_page: toValue(perPage) },
                    { preserveState: true, preserveScroll: true, replace: true },
                ),
            300,
        );
    }
    onBeforeUnmount(() => clearTimeout(timer));
    return { search, filter };
}

export function exportEndingRows(filename: string, rows: (string | number | null | undefined)[][]) {
    const csv = rows
        .map((row) =>
            row
                .map((value) => {
                    let text = String(value ?? '');
                    if (/^[\s]*[=+@-]/.test(text)) text = `'${text}`;
                    return `"${text.replaceAll('"', '""')}"`;
                })
                .join(','),
        )
        .join('\r\n');
    const url = URL.createObjectURL(new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8;' }));
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
}
