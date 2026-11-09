import type { ComponentChildren } from 'preact';
import { useState } from 'preact/hooks';
import {
    firstLine,
    formatTime,
    hasContent,
    levelClasses,
    splitException,
} from '../format';
import type { LogEntry } from '../types';
import { FileLocation, StackTrace } from './Source';

/**
 * Saves an entry as a bookmark with a note.
 */
export type SaveEntry = (entry: LogEntry, note: string) => Promise<void>;

interface Props {
    entries: LogEntry[];
    onSave?: SaveEntry;
    /** Offsets of entries that just arrived, which are highlighted. */
    fresh?: Set<number>;
}

/**
 * The entries, each expandable to show its details.
 */
export function EntryList({ entries, onSave, fresh }: Props) {
    const [expanded, setExpanded] = useState<Set<number>>(new Set());

    function toggle(offset: number) {
        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(offset)) {
                next.delete(offset);
            } else {
                next.add(offset);
            }

            return next;
        });
    }

    return (
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-800">
            {entries.map((entry) => (
                <EntryRow
                    key={entry.offset}
                    entry={entry}
                    open={expanded.has(entry.offset)}
                    onToggle={() => toggle(entry.offset)}
                    onSave={onSave}
                    fresh={fresh?.has(entry.offset) ?? false}
                />
            ))}
        </ul>
    );
}

interface RowProps {
    entry: LogEntry;
    open: boolean;
    onToggle: () => void;
    onSave?: SaveEntry;
    fresh: boolean;
}

function EntryRow({ entry, open, onToggle, onSave, fresh }: RowProps) {
    return (
        <li class={fresh ? 'vlog-fresh' : undefined}>
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                class="flex w-full items-start gap-3 px-4 py-2 text-left hover:bg-zinc-50 dark:hover:bg-zinc-900"
            >
                <span
                    class={`mt-0.5 w-20 shrink-0 rounded px-1.5 py-0.5 text-center text-[11px] font-semibold uppercase ${levelClasses(entry.level)}`}
                >
                    {entry.level ?? entry.raw_level ?? 'none'}
                </span>
                <span class="mt-0.5 w-36 shrink-0 font-mono text-xs text-zinc-500 tabular-nums">
                    {formatTime(entry.time)}
                </span>
                {entry.channel !== null && (
                    <span class="mt-0.5 hidden shrink-0 text-xs text-zinc-400 md:inline">
                        {entry.channel}
                    </span>
                )}
                <span class="min-w-0 flex-1 truncate font-mono text-sm">
                    {firstLine(entry.message) || <em class="text-zinc-400">No message</em>}
                </span>
                {entry.stack.length > 0 && (
                    <span class="mt-0.5 shrink-0 text-xs text-zinc-400" title="Has a stack trace">
                        {entry.stack.length} {entry.stack.length === 1 ? 'frame' : 'frames'}
                    </span>
                )}
            </button>
            {open && <EntryDetails entry={entry} onSave={onSave} />}
        </li>
    );
}

/**
 * Saves an entry with an optional note.
 */
function SaveControl({ entry, onSave }: { entry: LogEntry; onSave: SaveEntry }) {
    const [state, setState] = useState<'idle' | 'editing' | 'saving' | 'saved'>('idle');
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);

    async function save() {
        setState('saving');
        setError(null);

        try {
            await onSave(entry, note.trim());
            setState('saved');
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'The entry could not be saved.');
            setState('editing');
        }
    }

    if (state === 'saved') {
        return <span class="text-xs text-emerald-700 dark:text-emerald-400">Saved</span>;
    }

    if (state === 'idle') {
        return (
            <button type="button" onClick={() => setState('editing')} class="text-xs text-zinc-500 underline hover:text-zinc-900 dark:hover:text-zinc-100">
                Save
            </button>
        );
    }

    return (
        <span class="flex flex-wrap items-center gap-2">
            <input
                type="text"
                value={note}
                maxLength={2000}
                autoFocus
                placeholder="Add a note (optional)"
                aria-label="Note"
                onInput={(event) => setNote(event.currentTarget.value)}
                onKeyDown={(event) => event.key === 'Enter' && save()}
                class="w-56 rounded-md border border-zinc-300 bg-white px-2 py-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
            />
            <button type="button" disabled={state === 'saving'} onClick={save} class="text-xs font-medium underline">
                {state === 'saving' ? 'Saving…' : 'Save'}
            </button>
            <button type="button" onClick={() => setState('idle')} class="text-xs text-zinc-500 underline">
                Cancel
            </button>
            {error !== null && <span role="alert" class="text-xs text-red-600 dark:text-red-400">{error}</span>}
        </span>
    );
}

/**
 * An entry's full message, exception, stack trace and context.
 */
export function EntryDetails({ entry, onSave }: { entry: LogEntry; onSave?: SaveEntry }) {
    const { exception, rest } = splitException(entry.context);
    const [copied, setCopied] = useState(false);

    async function copy() {
        const text = [entry.message, hasContent(rest) ? JSON.stringify(rest, null, 2) : '']
            .filter((part) => part !== '')
            .join('\n\n');

        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            setCopied(false);
        }
    }

    return (
        <div class="space-y-4 bg-zinc-50 px-4 py-4 text-sm dark:bg-zinc-900/60">
            {entry.truncated && (
                <p class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                    This entry is larger than the size limit, so only its start is shown.
                </p>
            )}
            <Section title="Message" action={
                <span class="flex items-center gap-3">
                    {onSave !== undefined && <SaveControl entry={entry} onSave={onSave} />}
                    <button type="button" onClick={copy} class="text-xs text-zinc-500 underline hover:text-zinc-900 dark:hover:text-zinc-100">
                        {copied ? 'Copied' : 'Copy'}
                    </button>
                </span>
            }>
                <pre class="font-mono text-xs break-words whitespace-pre-wrap">{entry.message}</pre>
            </Section>
            {exception !== null && (
                <Section title="Exception">
                    <pre class="font-mono text-xs break-words whitespace-pre-wrap text-red-700 dark:text-red-400">{exception}</pre>
                </Section>
            )}
            {entry.origin !== null && entry.origin !== undefined && (
                <Section title="Thrown in">
                    <div class="font-mono text-xs">
                        <FileLocation file={entry.origin.file} line={entry.origin.line} />
                    </div>
                </Section>
            )}
            {entry.stack.length > 0 && (
                <Section title="Stack trace">
                    <StackTrace frames={entry.stack} />
                </Section>
            )}
            {hasContent(rest) && (
                <Section title="Context">
                    <pre class="overflow-x-auto font-mono text-xs">{JSON.stringify(rest, null, 2)}</pre>
                </Section>
            )}
        </div>
    );
}

function Section({ title, action, children }: { title: string; action?: ComponentChildren; children: ComponentChildren }) {
    return (
        <section>
            <div class="mb-1 flex items-center justify-between">
                <h3 class="text-xs font-semibold tracking-wide text-zinc-500 uppercase">{title}</h3>
                {action}
            </div>
            {children}
        </section>
    );
}
