import type { QueryParams } from './api';

export type Order = 'newest' | 'oldest';

/**
 * What the user is filtering the entries by.
 */
export interface Filters {
    levels: string[];
    query: string;
    regex: boolean;
    caseSensitive: boolean;
    from: string;
    to: string;
    order: Order;
    group: number | null;
}

/**
 * Everything the page keeps in its URL, so a view can be shared or reloaded.
 */
export interface ViewState {
    fileId: string | null;
    page: number;
    view: View;
    filters: Filters;
}

/**
 * Whether the page lists entries or groups of similar entries.
 */
export type View = 'entries' | 'groups';

export const defaultFilters: Filters = {
    levels: [],
    query: '',
    regex: false,
    caseSensitive: false,
    from: '',
    to: '',
    order: 'newest',
    group: null,
};

/**
 * Determine whether any filter is set, which switches from paging through
 * every entry to searching.
 */
export function isFiltering(filters: Filters): boolean {
    return (
        filters.levels.length > 0 ||
        filters.query !== '' ||
        filters.from !== '' ||
        filters.to !== '' ||
        filters.group !== null
    );
}

/**
 * Get the search endpoint's query parameters for the filters.
 */
export function searchParams(
    filters: Filters,
    cursor: number | null,
    limit: number,
): QueryParams {
    return {
        levels:
            filters.levels.length > 0 ? filters.levels.join(',') : undefined,
        q: filters.query,
        regex: filters.regex ? '1' : undefined,
        case: filters.caseSensitive ? '1' : undefined,
        from: filters.from,
        to: filters.to,
        order: filters.order,
        group: filters.group === null ? undefined : String(filters.group),
        cursor: cursor === null ? undefined : String(cursor),
        limit: String(limit),
    };
}

/**
 * Read the view from a URL query string. Values that aren't valid fall back
 * to their defaults.
 */
export function readViewState(search: string): ViewState {
    const params = new URLSearchParams(search);
    const page = Number.parseInt(params.get('page') ?? '1', 10);
    const group = Number.parseInt(params.get('group') ?? '', 10);
    const levels = (params.get('levels') ?? '')
        .split(',')
        .map((level) => level.trim())
        .filter((level) => level !== '');

    return {
        fileId: params.get('file') || null,
        page: Number.isFinite(page) && page > 0 ? page : 1,
        view: params.get('view') === 'groups' ? 'groups' : 'entries',
        filters: {
            levels,
            query: params.get('q') ?? '',
            regex: params.get('regex') === '1',
            caseSensitive: params.get('case') === '1',
            from: params.get('from') ?? '',
            to: params.get('to') ?? '',
            order: params.get('order') === 'oldest' ? 'oldest' : 'newest',
            group: Number.isFinite(group) && group > 0 ? group : null,
        },
    };
}

/**
 * Write the view as a URL query string, leaving out default values.
 */
export function writeViewState(state: ViewState): string {
    const params = new URLSearchParams();
    const { filters } = state;

    if (state.fileId !== null) {
        params.set('file', state.fileId);
    }

    if (state.view !== 'entries') {
        params.set('view', state.view);
    }

    if (state.page > 1 && !isFiltering(filters)) {
        params.set('page', String(state.page));
    }

    if (filters.levels.length > 0) {
        params.set('levels', filters.levels.join(','));
    }

    if (filters.query !== '') {
        params.set('q', filters.query);
    }

    if (filters.regex) {
        params.set('regex', '1');
    }

    if (filters.caseSensitive) {
        params.set('case', '1');
    }

    if (filters.from !== '') {
        params.set('from', filters.from);
    }

    if (filters.to !== '') {
        params.set('to', filters.to);
    }

    if (filters.group !== null) {
        params.set('group', String(filters.group));
    }

    if (filters.order !== 'newest') {
        params.set('order', filters.order);
    }

    const query = params.toString();

    return query === '' ? '' : `?${query}`;
}
