import { describe, expect, it } from 'vitest';
import {
    defaultFilters,
    isFiltering,
    readViewState,
    searchParams,
    writeViewState,
} from './filters';

describe('readViewState', () => {
    it('reads the file, page and filters from the URL', () => {
        const state = readViewState(
            '?file=abc&page=3&view=groups&levels=error,warning&q=timeout&regex=1&case=1&from=2026-11-01T00:00&to=2026-11-02T00:00&order=oldest&group=7',
        );

        expect(state).toEqual({
            fileId: 'abc',
            page: 3,
            view: 'groups',
            filters: {
                levels: ['error', 'warning'],
                query: 'timeout',
                regex: true,
                caseSensitive: true,
                from: '2026-11-01T00:00',
                to: '2026-11-02T00:00',
                order: 'oldest',
                group: 7,
            },
        });
    });

    it('falls back to defaults for missing and invalid values', () => {
        expect(readViewState('?page=-2&order=sideways&levels=,&view=other&group=abc')).toEqual({
            fileId: null,
            page: 1,
            view: 'entries',
            filters: defaultFilters,
        });
    });
});

describe('writeViewState', () => {
    it('leaves default values out of the URL', () => {
        expect(writeViewState({ fileId: null, page: 1, view: 'entries', filters: defaultFilters })).toBe('');
    });

    it('writes a state that reads back the same', () => {
        const state = {
            fileId: 'abc',
            page: 1,
            view: 'groups' as const,
            filters: { ...defaultFilters, levels: ['error'], query: 'a&b', regex: true, group: 3 },
        };

        expect(readViewState(writeViewState(state))).toEqual(state);
    });

    it('leaves the page number out while filtering, since search results use a cursor', () => {
        const query = writeViewState({
            fileId: 'abc',
            page: 4,
            view: 'entries',
            filters: { ...defaultFilters, query: 'timeout' },
        });

        expect(query).toBe('?file=abc&q=timeout');
    });
});

describe('isFiltering', () => {
    it('is true when any filter is set, but not for the order alone', () => {
        expect(isFiltering(defaultFilters)).toBe(false);
        expect(isFiltering({ ...defaultFilters, order: 'oldest' })).toBe(false);
        expect(isFiltering({ ...defaultFilters, levels: ['info'] })).toBe(true);
        expect(isFiltering({ ...defaultFilters, from: '2026-11-01T00:00' })).toBe(true);
        expect(isFiltering({ ...defaultFilters, group: 4 })).toBe(true);
    });
});

describe('searchParams', () => {
    it('builds the search parameters, leaving unset options out', () => {
        expect(searchParams({ ...defaultFilters, levels: ['error', 'critical'], query: 'db' }, null, 50)).toEqual({
            levels: 'error,critical',
            q: 'db',
            regex: undefined,
            case: undefined,
            from: '',
            to: '',
            order: 'newest',
            group: undefined,
            cursor: undefined,
            limit: '50',
        });
    });

    it('includes the cursor and options when set', () => {
        const params = searchParams({ ...defaultFilters, regex: true, caseSensitive: true }, 120, 25);

        expect([params.regex, params.case, params.cursor]).toEqual(['1', '1', '120']);
    });
});
