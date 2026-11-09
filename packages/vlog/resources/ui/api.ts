import type {
    Bookmark,
    EntriesResponse,
    GroupsResponse,
    LogFileDetails,
    LogFileSummary,
    SearchAllResponse,
    SearchResponse,
    SourceLines,
} from './types';

export type QueryParams = Record<string, string | undefined>;

/**
 * An error response from the API, with the server's message.
 */
export class ApiError extends Error {
    readonly status: number;

    constructor(message: string, status: number) {
        super(message);
        this.status = status;
    }
}

/**
 * Build a URL from a base, a path and query parameters, leaving out empty
 * parameters.
 */
export function buildUrl(
    baseUrl: string,
    path: string,
    params: QueryParams = {},
): string {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== '') {
            query.set(key, value);
        }
    }

    const queryString = query.toString();

    return (
        baseUrl.replace(/\/+$/, '') +
        path +
        (queryString === '' ? '' : `?${queryString}`)
    );
}

type Method = 'GET' | 'POST' | 'PATCH' | 'DELETE';

export function createApi(baseUrl: string, csrfToken: string | null = null) {
    async function request<T>(
        method: Method,
        path: string,
        options: { params?: QueryParams; body?: unknown; signal?: AbortSignal } = {},
    ): Promise<T> {
        const headers: Record<string, string> = { Accept: 'application/json' };

        // Requests that change something must say they come from Vlog, and
        // carry Laravel's CSRF token when there is one.
        if (method !== 'GET') {
            headers['X-Vlog-Request'] = '1';
            headers['Content-Type'] = 'application/json';

            if (csrfToken !== null) {
                headers['X-CSRF-TOKEN'] = csrfToken;
            }
        }

        const response = await fetch(buildUrl(baseUrl, path, options.params), {
            method,
            headers,
            body: options.body === undefined ? undefined : JSON.stringify(options.body),
            credentials: 'same-origin',
            signal: options.signal,
        });

        let body: unknown = null;

        try {
            body = await response.json();
        } catch {
            body = null;
        }

        if (!response.ok) {
            const message =
                body !== null &&
                typeof body === 'object' &&
                typeof (body as { message?: unknown }).message === 'string' &&
                (body as { message: string }).message !== ''
                    ? (body as { message: string }).message
                    : `The request failed with status ${response.status}.`;

            throw new ApiError(message, response.status);
        }

        return body as T;
    }

    const file = (id: string) => `/files/${encodeURIComponent(id)}`;

    return {
        files: (signal?: AbortSignal) =>
            request<{ data: LogFileSummary[] }>('GET', '/files', { signal }),
        file: (id: string, signal?: AbortSignal) =>
            request<{ data: LogFileDetails }>('GET', file(id), { signal }),
        entries: (id: string, params: QueryParams, signal?: AbortSignal) =>
            request<EntriesResponse>('GET', `${file(id)}/entries`, { params, signal }),
        search: (id: string, params: QueryParams, signal?: AbortSignal) =>
            request<SearchResponse>('GET', `${file(id)}/search`, { params, signal }),

        searchAll: (params: QueryParams, signal?: AbortSignal) =>
            request<SearchAllResponse>('GET', '/search', { params, signal }),
        source: (path: string, line: number, signal?: AbortSignal) =>
            request<{ data: SourceLines }>('GET', '/source', { params: { path, line: String(line) }, signal }),

        groups: (id: string, params: QueryParams, signal?: AbortSignal) =>
            request<GroupsResponse>('GET', `${file(id)}/groups`, { params, signal }),

        bookmarks: (signal?: AbortSignal) =>
            request<{ data: Bookmark[] }>('GET', '/bookmarks', { signal }),
        addBookmark: (fileId: string, offset: number, note: string) =>
            request<{ data: Bookmark }>('POST', '/bookmarks', {
                body: { file: fileId, offset, note },
            }),
        updateBookmark: (id: string, note: string) =>
            request<{ data: Bookmark }>('PATCH', `/bookmarks/${encodeURIComponent(id)}`, {
                body: { note },
            }),
        deleteBookmark: (id: string) =>
            request<unknown>('DELETE', `/bookmarks/${encodeURIComponent(id)}`),

        clearFile: (id: string) => request<unknown>('POST', `${file(id)}/clear`),
        deleteFile: (id: string) => request<unknown>('DELETE', file(id)),
        writeEntry: (level: string, message: string) =>
            request<unknown>('POST', '/entries', { body: { level, message } }),

        exportUrl: (id: string, params: QueryParams) =>
            buildUrl(baseUrl, `${file(id)}/export`, params),
        downloadUrl: (id: string) => buildUrl(baseUrl, `${file(id)}/download`),
    };
}

export type Api = ReturnType<typeof createApi>;
