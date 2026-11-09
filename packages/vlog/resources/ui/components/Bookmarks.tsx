import { useState } from 'preact/hooks';
import { firstLine, formatTime, levelClasses } from '../format';
import type { Bookmark } from '../types';
import { EntryDetails } from './EntryList';

interface ListProps {
    bookmarks: Bookmark[] | null;
    selectedId: string | null;
    error: string | null;
    onSelect: (id: string) => void;
}

/**
 * The saved entries, newest first.
 */
export function BookmarkList({ bookmarks, selectedId, error, onSelect }: ListProps) {
    if (error !== null) {
        return <p class="p-4 text-sm text-red-600 dark:text-red-400">{error}</p>;
    }

    if (bookmarks === null) {
        return <p class="p-4 text-sm text-zinc-500">Loading saved entries…</p>;
    }

    if (bookmarks.length === 0) {
        return (
            <p class="p-4 text-sm text-zinc-500">
                No saved entries yet. Open an entry and choose Save to keep it here, even after its log file is rotated.
            </p>
        );
    }

    return (
        <ul class="space-y-0.5 p-2">
            {bookmarks.map((bookmark) => {
                const selected = bookmark.id === selectedId;

                return (
                    <li key={bookmark.id}>
                        <button
                            type="button"
                            onClick={() => onSelect(bookmark.id)}
                            aria-current={selected ? 'true' : undefined}
                            class={`w-full rounded-md px-3 py-2 text-left transition-colors ${
                                selected
                                    ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                    : 'hover:bg-zinc-100 dark:hover:bg-zinc-800'
                            }`}
                        >
                            <span class="block truncate text-sm font-medium">
                                {bookmark.note || firstLine(bookmark.entry.message)}
                            </span>
                            <span class={`block truncate text-xs ${selected ? 'opacity-75' : 'text-zinc-500'}`}>
                                {bookmark.file} · {formatTime(bookmark.entry.time)}
                            </span>
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}

interface DetailProps {
    bookmark: Bookmark;
    onUpdateNote: (note: string) => Promise<void>;
    onDelete: () => Promise<void>;
}

/**
 * One saved entry, with its note.
 */
export function BookmarkDetail({ bookmark, onUpdateNote, onDelete }: DetailProps) {
    const [note, setNote] = useState(bookmark.note);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const entry = bookmark.entry;

    async function run(action: () => Promise<void>, done: string) {
        setBusy(true);
        setMessage(null);

        try {
            await action();
            setMessage(done);
        } catch (caught) {
            setMessage(caught instanceof Error ? caught.message : 'Something went wrong.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <div class="min-h-0 flex-1 overflow-y-auto">
            <header class="flex flex-wrap items-center gap-3 px-4 pt-4">
                <span class={`rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase ${levelClasses(entry.level)}`}>
                    {entry.level ?? entry.raw_level ?? 'none'}
                </span>
                <h2 class="min-w-0 flex-1 truncate text-lg font-semibold">{firstLine(entry.message)}</h2>
            </header>
            <p class="px-4 pt-1 text-sm text-zinc-500">
                From {bookmark.file} · logged {formatTime(entry.time)} · saved {formatTime(bookmark.created_at)}
            </p>
            <div class="space-y-2 p-4">
                <label class="block text-xs font-semibold tracking-wide text-zinc-500 uppercase" for="vlog-note">
                    Note
                </label>
                <textarea
                    id="vlog-note"
                    value={note}
                    maxLength={2000}
                    rows={3}
                    onInput={(event) => setNote(event.currentTarget.value)}
                    class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                />
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        disabled={busy || note === bookmark.note}
                        onClick={() => run(() => onUpdateNote(note), 'Note saved.')}
                        class="rounded-md bg-zinc-900 px-3 py-1 text-sm text-white disabled:opacity-40 dark:bg-zinc-100 dark:text-zinc-900"
                    >
                        Save note
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => {
                            if (window.confirm('Remove this saved entry? The log file is not changed.')) {
                                void run(onDelete, 'Removed.');
                            }
                        }}
                        class="text-sm text-red-600 underline disabled:opacity-40 dark:text-red-400"
                    >
                        Remove
                    </button>
                    {message !== null && <span role="status" class="text-sm text-zinc-500">{message}</span>}
                </div>
            </div>
            <EntryDetails entry={entry} />
        </div>
    );
}
