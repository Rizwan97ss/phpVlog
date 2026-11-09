import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import { ApiError, createApi } from '../api';
import {
    defaultFilters,
    isFiltering,
    readViewState,
    searchParams,
    writeViewState,
    type Filters,
    type View,
} from '../filters';
import { loadEditorSettings, saveEditorSettings, type EditorSettings } from '../editor';
import { formatBytes } from '../format';
import type {
    Bookmark,
    LogEntry,
    LogFileDetails,
    LogFileSummary,
    PageMeta,
    SearchMeta,
    VlogConfig,
} from '../types';
import { BookmarkDetail, BookmarkList } from './Bookmarks';
import { EditorSettingsButton } from './EditorSettingsButton';
import { EntryList } from './EntryList';
import { FileActions } from './FileActions';
import { FileList } from './FileList';
import { Groups } from './Groups';
import { SearchAll } from './SearchAll';
import { DevToolsContext, type DevTools } from './Source';
import { ThemeToggle } from './ThemeToggle';
import { Toolbar } from './Toolbar';

const PER_PAGE = 50;
const SEARCH_DELAY_MS = 300;
const INDEX_POLL_MS = 1000;
const LIVE_POLL_MS = 2000;

/**
 * Get a readable message from a failed request.
 */
function errorMessage(error: unknown): string {
    if (error instanceof ApiError) {
        return error.message;
    }

    return 'Vlog could not reach the server. Check your connection and try again.';
}

function isAbort(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
}

export function App({ config }: { config: VlogConfig }) {
    const api = useMemo(() => createApi(config.apiUrl, config.csrfToken), [config.apiUrl, config.csrfToken]);
    const initial = useMemo(() => readViewState(window.location.search), []);

    const [files, setFiles] = useState<LogFileSummary[] | null>(null);
    const [filesError, setFilesError] = useState<string | null>(null);
    const [fileId, setFileId] = useState<string | null>(initial.fileId);
    const [details, setDetails] = useState<LogFileDetails | null>(null);

    const [filters, setFilters] = useState<Filters>(initial.filters);
    const [queryInput, setQueryInput] = useState(initial.filters.query);
    const [page, setPage] = useState(initial.page);
    const [view, setView] = useState<View>(initial.view);
    const [groupLabel, setGroupLabel] = useState<string | null>(null);

    const [entries, setEntries] = useState<LogEntry[]>([]);
    const [pageMeta, setPageMeta] = useState<PageMeta | null>(null);
    const [searchMeta, setSearchMeta] = useState<SearchMeta | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [reloadKey, setReloadKey] = useState(0);

    // Following the log: new entries are fetched every few seconds and
    // highlighted as they arrive.
    const [live, setLive] = useState(false);
    const [liveTick, setLiveTick] = useState(0);
    const [fresh, setFresh] = useState<Set<number>>(new Set());
    const liveRefresh = useRef(false);
    const entriesRef = useRef<LogEntry[]>([]);

    // A search of every file, shown instead of the selected file.
    const [globalInput, setGlobalInput] = useState('');
    const [globalQuery, setGlobalQuery] = useState<string | null>(null);

    const [editorSettings, setEditorSettings] = useState<EditorSettings>(() => loadEditorSettings(config.editor));
    const devTools = useMemo<DevTools>(
        () => ({ api, canPreviewSource: config.canPreviewSource, editor: editorSettings }),
        [api, config.canPreviewSource, editorSettings],
    );

    function changeEditorSettings(settings: EditorSettings) {
        setEditorSettings(settings);
        saveEditorSettings(settings);
    }

    const [tab, setTab] = useState<'files' | 'saved'>('files');
    const [bookmarks, setBookmarks] = useState<Bookmark[] | null>(null);
    const [bookmarksError, setBookmarksError] = useState<string | null>(null);
    const [selectedBookmarkId, setSelectedBookmarkId] = useState<string | null>(null);
    const selectedBookmark = bookmarks?.find((bookmark) => bookmark.id === selectedBookmarkId) ?? null;

    // Load the saved entries when the Saved tab is opened.
    useEffect(() => {
        if (tab !== 'saved') {
            return;
        }

        const controller = new AbortController();

        api.bookmarks(controller.signal)
            .then(({ data }) => {
                setBookmarks(data);
                setBookmarksError(null);
            })
            .catch((caught: unknown) => {
                if (!isAbort(caught)) {
                    setBookmarksError(errorMessage(caught));
                }
            });

        return () => controller.abort();
    }, [api, tab, reloadKey]);
    const searchRef = useRef<HTMLInputElement>(null);
    const filtering = isFiltering(filters);

    // Load the file list.
    useEffect(() => {
        const controller = new AbortController();

        api.files(controller.signal)
            .then(({ data }) => {
                setFiles(data);
                setFilesError(null);
                setFileId((current) =>
                    current !== null && data.some((file) => file.id === current)
                        ? current
                        : (data[0]?.id ?? null),
                );
            })
            .catch((caught: unknown) => {
                if (!isAbort(caught)) {
                    setFilesError(errorMessage(caught));
                }
            });

        return () => controller.abort();
    }, [api, reloadKey]);

    // Load the selected file's details, polling while it's still being indexed.
    useEffect(() => {
        if (fileId === null) {
            setDetails(null);

            return;
        }

        const controller = new AbortController();
        let timer: number | undefined;

        const load = (isPoll: boolean) => {
            api.file(fileId, controller.signal)
                .then(({ data }) => {
                    setDetails(data);

                    if (!data.complete) {
                        timer = window.setTimeout(() => load(true), INDEX_POLL_MS);
                    } else if (isPoll) {
                        setReloadKey((key) => key + 1);
                    }
                })
                .catch((caught: unknown) => {
                    if (!isAbort(caught)) {
                        setError(errorMessage(caught));
                    }
                });
        };

        load(false);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [api, fileId, reloadKey, liveTick]);

    useEffect(() => {
        entriesRef.current = entries;
    }, [entries]);

    // While following, check for new entries, unless the tab is hidden or
    // the user has moved away from the newest entries.
    useEffect(() => {
        if (!live || fileId === null || view !== 'entries' || page !== 1 || filters.order !== 'newest') {
            return;
        }

        const timer = window.setInterval(() => {
            if (!document.hidden && entriesRef.current.length <= PER_PAGE) {
                liveRefresh.current = true;
                setLiveTick((tick) => tick + 1);
            }
        }, LIVE_POLL_MS);

        return () => window.clearInterval(timer);
    }, [live, fileId, view, page, filters.order]);

    // Wait until typing pauses before searching.
    useEffect(() => {
        if (queryInput === filters.query) {
            return;
        }

        const timer = window.setTimeout(() => {
            setFilters((current) => ({ ...current, query: queryInput }));
        }, SEARCH_DELAY_MS);

        return () => window.clearTimeout(timer);
    }, [queryInput, filters.query]);

    // Load entries: a page of everything, or the first page of search results.
    useEffect(() => {
        if (fileId === null) {
            setEntries([]);

            return;
        }

        const controller = new AbortController();
        const silent = liveRefresh.current;
        liveRefresh.current = false;

        if (!silent) {
            setLoading(true);
            setError(null);
        }

        // Mark the entries that weren't shown before a refresh while following.
        const markFresh = (received: LogEntry[]) => {
            if (!silent) {
                setFresh(new Set());

                return;
            }

            const known = new Set(entriesRef.current.map((entry) => entry.offset));
            setFresh(new Set(received.filter((entry) => !known.has(entry.offset)).map((entry) => entry.offset)));
        };

        const request = filtering
            ? api
                  .search(fileId, searchParams(filters, null, PER_PAGE), controller.signal)
                  .then((response) => {
                      markFresh(response.data);
                      setEntries(response.data);
                      setSearchMeta(response.meta);
                      setPageMeta(null);
                  })
            : api
                  .entries(
                      fileId,
                      { page: String(page), per_page: String(PER_PAGE), order: filters.order },
                      controller.signal,
                  )
                  .then((response) => {
                      markFresh(response.data);
                      setEntries(response.data);
                      setPageMeta(response.meta);
                      setSearchMeta(null);
                  });

        request
            .catch((caught: unknown) => {
                // A failed refresh while following keeps what's shown.
                if (!isAbort(caught) && !silent) {
                    setEntries([]);
                    setError(errorMessage(caught));
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [api, fileId, filters, filtering, page, reloadKey, liveTick]);

    // Look up the pattern of a group opened from a shared link.
    useEffect(() => {
        if (fileId === null || filters.group === null || groupLabel !== null) {
            return;
        }

        const controller = new AbortController();
        const group = filters.group;

        api.groups(fileId, { limit: '1000' }, controller.signal)
            .then(({ data }) => setGroupLabel(data.find((candidate) => candidate.id === group)?.pattern ?? null))
            .catch(() => undefined);

        return () => controller.abort();
    }, [api, fileId, filters.group, groupLabel]);

    // Keep the URL in step with the view, so it can be shared or reloaded.
    useEffect(() => {
        const query = writeViewState({ fileId, page, view, filters });

        window.history.replaceState(null, '', window.location.pathname + query);
    }, [fileId, page, filters]);

    // Press "/" to jump to the search box.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            const typing = target !== null && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);

            if (event.key === '/' && !typing) {
                event.preventDefault();
                searchRef.current?.focus();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    function loadMore() {
        if (fileId === null || searchMeta === null || searchMeta.next_cursor === null) {
            return;
        }

        setLoading(true);

        api.search(fileId, searchParams(filters, searchMeta.next_cursor, PER_PAGE))
            .then((response) => {
                setEntries((current) => [...current, ...response.data]);
                setSearchMeta(response.meta);
            })
            .catch((caught: unknown) => setError(errorMessage(caught)))
            .finally(() => setLoading(false));
    }

    function changeFilters(changes: Partial<Filters>) {
        setFilters((current) => ({ ...current, ...changes }));
        setPage(1);
    }

    function selectFile(id: string) {
        if (id !== fileId) {
            setFilters((current) => ({ ...current, group: null }));
            setGroupLabel(null);
            setFileId(id);
            setPage(1);
            setDetails(null);
            setEntries([]);
        }
    }

    async function saveEntryIn(id: string, entry: LogEntry, note: string) {
        const { data } = await api.addBookmark(id, entry.offset, note);
        setBookmarks((current) => (current === null ? null : [data, ...current]));
    }

    async function saveEntry(entry: LogEntry, note: string) {
        if (fileId !== null) {
            await saveEntryIn(fileId, entry, note);
        }
    }

    /**
     * Open a file from the search of every file, searching it for the same text.
     */
    function openFromSearch(id: string, query: string) {
        setGlobalQuery(null);
        selectFile(id);
        setQueryInput(query);
        setFilters((current) => ({ ...defaultFilters, order: current.order, query }));
        setPage(1);
        setView('entries');
    }

    function toggleLive() {
        if (!live) {
            setPage(1);
            setFilters((current) => ({ ...current, order: 'newest' }));
            setView('entries');
        }

        setLive(!live);
    }

    async function updateBookmark(id: string, note: string) {
        const { data } = await api.updateBookmark(id, note);
        setBookmarks((current) => current?.map((bookmark) => (bookmark.id === id ? data : bookmark)) ?? null);
    }

    async function deleteBookmark(id: string) {
        await api.deleteBookmark(id);
        setBookmarks((current) => current?.filter((bookmark) => bookmark.id !== id) ?? null);
        setSelectedBookmarkId(null);
    }

    async function clearFile() {
        if (fileId !== null) {
            await api.clearFile(fileId);
            setPage(1);
            setReloadKey((key) => key + 1);
        }
    }

    async function deleteFile() {
        if (fileId !== null) {
            await api.deleteFile(fileId);
            setFileId(null);
            setDetails(null);
            setEntries([]);
            setReloadKey((key) => key + 1);
        }
    }

    async function writeEntry(level: string, message: string) {
        await api.writeEntry(level, message);
        setReloadKey((key) => key + 1);
    }

    const exportParams = { ...searchParams(filters, null, PER_PAGE), cursor: undefined, limit: undefined };

    const indexedPercent =
        details !== null && details.size > 0
            ? Math.min(100, Math.round((details.indexed_bytes / details.size) * 100))
            : 100;

    return (
        <DevToolsContext.Provider value={devTools}>
        <div class="flex h-full flex-col bg-white text-zinc-900 md:flex-row dark:bg-zinc-950 dark:text-zinc-100">
            <aside class="flex max-h-60 shrink-0 flex-col border-b border-zinc-200 md:max-h-none md:w-72 md:border-r md:border-b-0 dark:border-zinc-800">
                <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                    <h1 class="text-base font-semibold">{config.title}</h1>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            onClick={() => setReloadKey((key) => key + 1)}
                            title="Reload"
                            aria-label="Reload"
                            class="rounded-md px-2 py-1 text-sm text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        >
                            ↻
                        </button>
                        <EditorSettingsButton settings={editorSettings} onChange={changeEditorSettings} />
                        <ThemeToggle />
                    </div>
                </div>
                <div class="flex gap-1 border-b border-zinc-200 px-2 py-1.5 dark:border-zinc-800" role="tablist">
                    {(['files', 'saved'] as const).map((name) => (
                        <button
                            key={name}
                            type="button"
                            role="tab"
                            aria-selected={tab === name}
                            onClick={() => setTab(name)}
                            class={`flex-1 rounded-md px-2 py-1 text-sm ${
                                tab === name
                                    ? 'bg-zinc-100 font-medium dark:bg-zinc-800'
                                    : 'text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-900'
                            }`}
                        >
                            {name === 'files' ? 'Files' : `Saved${bookmarks !== null ? ` (${bookmarks.length})` : ''}`}
                        </button>
                    ))}
                </div>
                {tab === 'files' && (
                    <form
                        role="search"
                        class="border-b border-zinc-200 px-2 py-2 dark:border-zinc-800"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const query = globalInput.trim();
                            setGlobalQuery(query === '' ? null : query);
                        }}
                    >
                        <input
                            type="search"
                            value={globalInput}
                            onInput={(event) => setGlobalInput(event.currentTarget.value)}
                            placeholder="Search all files…"
                            aria-label="Search all files"
                            class="w-full rounded-md border border-zinc-300 bg-white px-2.5 py-1 text-sm outline-none focus:border-zinc-500 dark:border-zinc-700 dark:bg-zinc-900"
                        />
                    </form>
                )}
                <nav class="min-h-0 flex-1 overflow-y-auto" aria-label={tab === 'files' ? 'Log files' : 'Saved entries'}>
                    {tab === 'files' ? (
                        <FileList
                            files={files}
                            selectedId={globalQuery === null ? fileId : null}
                            error={filesError}
                            onSelect={(id) => {
                                setGlobalQuery(null);
                                selectFile(id);
                            }}
                        />
                    ) : (
                        <BookmarkList
                            bookmarks={bookmarks}
                            selectedId={selectedBookmarkId}
                            error={bookmarksError}
                            onSelect={setSelectedBookmarkId}
                        />
                    )}
                </nav>
            </aside>

            <main class="flex min-h-0 min-w-0 flex-1 flex-col">
                {tab === 'saved' ? (
                    selectedBookmark !== null ? (
                        <BookmarkDetail
                            key={selectedBookmark.id}
                            bookmark={selectedBookmark}
                            onUpdateNote={(note) => updateBookmark(selectedBookmark.id, note)}
                            onDelete={() => deleteBookmark(selectedBookmark.id)}
                        />
                    ) : (
                        <p class="p-8 text-center text-sm text-zinc-500">Choose a saved entry to see it here.</p>
                    )
                ) : globalQuery !== null ? (
                    <SearchAll
                        key={globalQuery}
                        api={api}
                        query={globalQuery}
                        onOpenFile={openFromSearch}
                        onClose={() => setGlobalQuery(null)}
                        onSave={saveEntryIn}
                    />
                ) : (
                <>
                {details !== null && (
                    <header class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 pt-4">
                        <h2 class="text-lg font-semibold break-all">{details.path}</h2>
                        <span class="text-sm text-zinc-500">
                            {details.entries.toLocaleString()} {details.entries === 1 ? 'entry' : 'entries'} · {formatBytes(details.size)} · {details.format}
                        </span>
                        {!details.complete && (
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                Indexing… {indexedPercent}%
                            </span>
                        )}
                    </header>
                )}

                {details !== null && fileId !== null && (
                    <FileActions
                        file={details}
                        canManage={config.canManage}
                        exportUrl={(format) => api.exportUrl(fileId, { ...exportParams, format })}
                        downloadUrl={api.downloadUrl(fileId)}
                        onClear={clearFile}
                        onDelete={deleteFile}
                        onWrite={writeEntry}
                    />
                )}

                {fileId !== null && (
                    <div class="flex items-center gap-1 px-4 pt-3">
                    <div class="flex gap-1" role="tablist" aria-label="View">
                        {(['entries', 'groups'] as const).map((name) => (
                            <button
                                key={name}
                                type="button"
                                role="tab"
                                aria-selected={view === name}
                                onClick={() => setView(name)}
                                class={`rounded-md px-3 py-1 text-sm ${
                                    view === name
                                        ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                        : 'text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800'
                                }`}
                            >
                                {name === 'entries' ? 'Entries' : 'Groups'}
                            </button>
                        ))}
                    </div>
                    <button
                        type="button"
                        aria-pressed={live}
                        onClick={toggleLive}
                        title={live ? 'Stop following new entries' : 'Show new entries as they are written'}
                        class={`ml-auto flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-sm ${
                            live
                                ? 'border-emerald-600 text-emerald-700 dark:border-emerald-500 dark:text-emerald-400'
                                : 'border-zinc-300 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800'
                        }`}
                    >
                        <span
                            aria-hidden="true"
                            class={`inline-block size-2 rounded-full ${live ? 'animate-pulse bg-emerald-500' : 'bg-zinc-400'}`}
                        />
                        {live ? 'Following' : 'Follow'}
                    </button>
                    </div>
                )}

                {fileId !== null && (
                    <Toolbar
                        levelsOnly={view === 'groups'}
                        groupLabel={groupLabel}
                        filters={filters}
                        queryInput={queryInput}
                        levelCounts={details?.levels ?? {}}
                        searchRef={searchRef}
                        onQueryInput={setQueryInput}
                        onChange={changeFilters}
                        onClear={() => {
                            setQueryInput('');
                            changeFilters({ ...defaultFilters, order: filters.order });
                        }}
                    />
                )}

                {view === 'groups' && fileId !== null ? (
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <Groups
                            api={api}
                            fileId={fileId}
                            levels={filters.levels}
                            reloadKey={reloadKey}
                            onOpen={(group) => {
                                setGroupLabel(group.pattern);
                                setQueryInput('');
                                setFilters({ ...defaultFilters, order: filters.order, group: group.id });
                                setPage(1);
                                setView('entries');
                            }}
                        />
                    </div>
                ) : (
                <>
                <div class="min-h-0 flex-1 overflow-y-auto" aria-busy={loading}>
                    {error !== null && (
                        <p role="alert" class="m-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
                            {error}
                        </p>
                    )}

                    {error === null && !loading && fileId !== null && entries.length === 0 && (
                        <p class="p-8 text-center text-sm text-zinc-500">
                            {filtering ? 'No entries match these filters.' : 'This log file has no entries yet.'}
                        </p>
                    )}

                    <EntryList key={`${fileId}-${JSON.stringify(filters)}-${page}`} entries={entries} onSave={saveEntry} fresh={fresh} />

                    {loading && <p class="p-4 text-center text-sm text-zinc-500">Loading…</p>}

                    {!loading && searchMeta !== null && searchMeta.has_more && (
                        <div class="p-4 text-center">
                            {searchMeta.reached_time_limit && (
                                <p class="mb-2 text-xs text-zinc-500">
                                    The search paused to keep the server responsive. More of the file is left to search.
                                </p>
                            )}
                            <button
                                type="button"
                                onClick={loadMore}
                                class="rounded-md border border-zinc-300 px-4 py-1.5 text-sm hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                            >
                                {searchMeta.reached_time_limit ? 'Continue searching' : 'Load more'}
                            </button>
                        </div>
                    )}
                </div>

                {pageMeta !== null && pageMeta.last_page > 1 && (
                    <footer class="flex items-center justify-between border-t border-zinc-200 px-4 py-2 text-sm dark:border-zinc-800">
                        <button
                            type="button"
                            disabled={page <= 1}
                            onClick={() => setPage(page - 1)}
                            class="rounded-md px-3 py-1 hover:bg-zinc-100 disabled:opacity-40 dark:hover:bg-zinc-800"
                        >
                            ← Previous
                        </button>
                        <span class="text-zinc-500 tabular-nums">
                            Page {page.toLocaleString()} of {pageMeta.last_page.toLocaleString()}
                        </span>
                        <button
                            type="button"
                            disabled={page >= pageMeta.last_page}
                            onClick={() => setPage(page + 1)}
                            class="rounded-md px-3 py-1 hover:bg-zinc-100 disabled:opacity-40 dark:hover:bg-zinc-800"
                        >
                            Next →
                        </button>
                    </footer>
                )}
                </>
                )}
                </>
                )}
            </main>
        </div>
        </DevToolsContext.Provider>
    );
}
