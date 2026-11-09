import { useEffect, useState } from 'preact/hooks';
import { ApiError, type Api } from '../api';
import { formatBytes } from '../format';
import type { FileMatches, LogEntry } from '../types';
import { EntryList } from './EntryList';

const PER_FILE = 5;

interface Props {
    api: Api;
    query: string;
    onOpenFile: (fileId: string, query: string) => void;
    onClose: () => void;
    onSave: (fileId: string, entry: LogEntry, note: string) => Promise<void>;
}

/**
 * The newest matches for a search in every log file.
 */
export function SearchAll({ api, query, onOpenFile, onClose, onSave }: Props) {
    const [results, setResults] = useState<FileMatches[]>([]);
    const [nextAfter, setNextAfter] = useState<string | null>(null);
    const [searched, setSearched] = useState({ files: 0, total: 0 });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    function load(after: string | null, signal?: AbortSignal) {
        setLoading(true);
        setError(null);

        return api
            .searchAll({ q: query, per_file: String(PER_FILE), after: after ?? undefined }, signal)
            .then((response) => {
                setResults((current) => (after === null ? response.data : [...current, ...response.data]));
                setNextAfter(response.meta.next_after);
                setSearched((current) => ({
                    files: (after === null ? 0 : current.files) + response.meta.searched_files,
                    total: response.meta.total_files,
                }));
            })
            .catch((caught: unknown) => {
                if (!(caught instanceof DOMException && caught.name === 'AbortError')) {
                    setError(caught instanceof ApiError ? caught.message : 'Vlog could not search the files.');
                }
            })
            .finally(() => {
                if (signal === undefined || !signal.aborted) {
                    setLoading(false);
                }
            });
    }

    useEffect(() => {
        const controller = new AbortController();
        setResults([]);
        void load(null, controller.signal);

        return () => controller.abort();
    }, [api, query]);

    return (
        <div class="flex min-h-0 flex-1 flex-col">
            <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="min-w-0 flex-1 truncate text-lg font-semibold">
                    All files: <span class="font-mono">{query}</span>
                </h2>
                <span class="text-sm text-zinc-500 tabular-nums">
                    Searched {searched.files.toLocaleString()} of {searched.total.toLocaleString()} files
                </span>
                <button type="button" onClick={onClose} class="rounded-md px-2 py-1 text-sm text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    Close
                </button>
            </header>
            <div class="min-h-0 flex-1 overflow-y-auto" aria-busy={loading}>
                {error !== null && (
                    <p role="alert" class="m-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
                        {error}
                    </p>
                )}
                {!loading && error === null && results.length === 0 && nextAfter === null && (
                    <p class="p-8 text-center text-sm text-zinc-500">No file has entries that match.</p>
                )}
                {results.map((result) => (
                    <section key={result.file.id} class="border-b border-zinc-200 dark:border-zinc-800">
                        <div class="flex flex-wrap items-baseline gap-x-3 bg-zinc-50 px-4 py-2 dark:bg-zinc-900">
                            <h3 class="min-w-0 truncate text-sm font-semibold" title={result.file.path}>{result.file.path}</h3>
                            <span class="text-xs text-zinc-500">{formatBytes(result.file.size)}</span>
                            {!result.complete && (
                                <span class="text-xs text-amber-700 dark:text-amber-400">Only partly searched</span>
                            )}
                            <button
                                type="button"
                                onClick={() => onOpenFile(result.file.id, query)}
                                class="ml-auto text-xs text-zinc-500 underline hover:text-zinc-900 dark:hover:text-zinc-100"
                            >
                                {result.has_more || !result.complete ? 'See all matches in this file' : 'Open this file'}
                            </button>
                        </div>
                        <EntryList entries={result.entries} onSave={(entry, note) => onSave(result.file.id, entry, note)} />
                    </section>
                ))}
                {loading && <p class="p-4 text-center text-sm text-zinc-500">Searching…</p>}
                {!loading && nextAfter !== null && (
                    <div class="p-4 text-center">
                        <p class="mb-2 text-xs text-zinc-500">The search paused to keep the server responsive. More files are left to search.</p>
                        <button
                            type="button"
                            onClick={() => void load(nextAfter)}
                            class="rounded-md border border-zinc-300 px-4 py-1.5 text-sm hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        >
                            Continue searching
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
