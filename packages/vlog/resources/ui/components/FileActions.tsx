import { useState } from 'preact/hooks';
import type { LogFileDetails } from '../types';

interface Props {
    file: LogFileDetails;
    canManage: boolean;
    exportUrl: (format: string) => string;
    downloadUrl: string;
    onClear: () => Promise<void>;
    onDelete: () => Promise<void>;
    onWrite: (level: string, message: string) => Promise<void>;
}

const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

const linkClasses =
    'rounded-md border border-zinc-300 px-2 py-1 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800';

/**
 * Export links for everyone, and download, clear, delete and add-entry
 * actions for users who can manage logs.
 */
export function FileActions({ file, canManage, exportUrl, downloadUrl, onClear, onDelete, onWrite }: Props) {
    const [writing, setWriting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function confirmThen(question: string, action: () => Promise<void>) {
        if (!window.confirm(question)) {
            return;
        }

        setError(null);

        try {
            await action();
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Something went wrong.');
        }
    }

    return (
        <div class="space-y-2 px-4 pt-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs text-zinc-500">Export results:</span>
                {['json', 'csv', 'txt'].map((format) => (
                    <a key={format} href={exportUrl(format)} class={linkClasses} download>
                        {format.toUpperCase()}
                    </a>
                ))}
                {canManage && (
                    <>
                        <span class="mx-1 h-4 border-l border-zinc-300 dark:border-zinc-700" aria-hidden="true" />
                        <a href={downloadUrl} class={linkClasses} download title="The original file, including any secrets">
                            Download original
                        </a>
                        <button type="button" class={linkClasses} onClick={() => setWriting(!writing)} aria-expanded={writing}>
                            Add entry
                        </button>
                        <button
                            type="button"
                            class={`${linkClasses} text-amber-700 dark:text-amber-400`}
                            onClick={() => confirmThen(`Empty ${file.path}? Every entry in it is permanently removed.`, onClear)}
                        >
                            Clear
                        </button>
                        <button
                            type="button"
                            class={`${linkClasses} text-red-600 dark:text-red-400`}
                            onClick={() => confirmThen(`Delete ${file.path}? This can't be undone.`, onDelete)}
                        >
                            Delete
                        </button>
                    </>
                )}
            </div>
            {error !== null && (
                <p role="alert" class="text-sm text-red-600 dark:text-red-400">{error}</p>
            )}
            {writing && <WriteEntryForm onWrite={onWrite} onClose={() => setWriting(false)} />}
        </div>
    );
}

function WriteEntryForm({ onWrite, onClose }: { onWrite: Props['onWrite']; onClose: () => void }) {
    const [level, setLevel] = useState('info');
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit(event: Event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await onWrite(level, message);
            setMessage('');
            onClose();
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'The entry could not be written.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} class="flex flex-wrap items-center gap-2 rounded-md bg-zinc-50 p-3 dark:bg-zinc-900">
            <select
                value={level}
                aria-label="Level"
                onChange={(event) => setLevel(event.currentTarget.value)}
                class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm capitalize dark:border-zinc-700 dark:bg-zinc-950"
            >
                {LEVELS.map((name) => (
                    <option key={name} value={name}>{name}</option>
                ))}
            </select>
            <input
                type="text"
                value={message}
                required
                maxLength={5000}
                autoFocus
                aria-label="Message"
                placeholder="For example: Deployed version 2.3"
                onInput={(event) => setMessage(event.currentTarget.value)}
                class="min-w-60 flex-1 rounded-md border border-zinc-300 bg-white px-3 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-950"
            />
            <button type="submit" disabled={busy || message.trim() === ''} class="rounded-md bg-zinc-900 px-3 py-1 text-sm text-white disabled:opacity-40 dark:bg-zinc-100 dark:text-zinc-900">
                {busy ? 'Writing…' : 'Write to log'}
            </button>
            <button type="button" onClick={onClose} class="text-sm text-zinc-500 underline">Cancel</button>
            <p class="w-full text-xs text-zinc-500">
                The entry is written to your application's default log channel.
            </p>
            {error !== null && <p role="alert" class="w-full text-sm text-red-600 dark:text-red-400">{error}</p>}
        </form>
    );
}
