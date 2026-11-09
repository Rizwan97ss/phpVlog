import type { Ref } from 'preact';
import type { Filters } from '../filters';
import { levelClasses, levelOrder } from '../format';

interface Props {
    filters: Filters;
    queryInput: string;
    levelCounts: Record<string, number>;
    searchRef: Ref<HTMLInputElement>;
    onQueryInput: (value: string) => void;
    onChange: (changes: Partial<Filters>) => void;
    onClear: () => void;
    /** Show only the level filters, for the groups view. */
    levelsOnly?: boolean;
    /** A description of the group being shown, when there is one. */
    groupLabel?: string | null;
}

/**
 * Search, level, date and order controls.
 */
export function Toolbar({
    filters,
    queryInput,
    levelCounts,
    searchRef,
    onQueryInput,
    onChange,
    onClear,
    levelsOnly = false,
    groupLabel = null,
}: Props) {
    const levels = levelOrder.filter((level) => (levelCounts[level] ?? 0) > 0);

    function toggleLevel(level: string) {
        onChange({
            levels: filters.levels.includes(level)
                ? filters.levels.filter((selected) => selected !== level)
                : [...filters.levels, level],
        });
    }

    const toggleClasses = (active: boolean) =>
        `rounded-md border px-2 py-1 font-mono text-xs ${
            active
                ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900'
                : 'border-zinc-300 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800'
        }`;

    return (
        <div class="space-y-3 border-b border-zinc-200 p-4 dark:border-zinc-800">
            {filters.group !== null && !levelsOnly && (
                <div class="flex items-center gap-2 rounded-md bg-sky-50 px-3 py-1.5 text-sm text-sky-900 dark:bg-sky-950 dark:text-sky-200">
                    <span class="min-w-0 flex-1 truncate">
                        Showing one group: <span class="font-mono">{groupLabel ?? `#${filters.group}`}</span>
                    </span>
                    <button type="button" class="shrink-0 text-xs underline" onClick={() => onChange({ group: null })}>
                        Show all entries
                    </button>
                </div>
            )}
            {!levelsOnly && (
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-60 flex-1">
                    <input
                        ref={searchRef}
                        type="search"
                        value={queryInput}
                        onInput={(event) => onQueryInput(event.currentTarget.value)}
                        placeholder={filters.regex ? 'Regular expression…' : 'Search messages, context and stack traces…'}
                        aria-label="Search"
                        class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm outline-none focus:border-zinc-500 focus:ring-2 focus:ring-zinc-200 dark:border-zinc-700 dark:bg-zinc-900 dark:focus:ring-zinc-700"
                    />
                </div>
                <button
                    type="button"
                    class={toggleClasses(filters.regex)}
                    aria-pressed={filters.regex}
                    title="Use a regular expression"
                    onClick={() => onChange({ regex: !filters.regex })}
                >
                    .*
                </button>
                <button
                    type="button"
                    class={toggleClasses(filters.caseSensitive)}
                    aria-pressed={filters.caseSensitive}
                    title="Match case"
                    onClick={() => onChange({ caseSensitive: !filters.caseSensitive })}
                >
                    Aa
                </button>
                <label class="flex items-center gap-1 text-xs text-zinc-500">
                    From
                    <input
                        type="datetime-local"
                        step="1"
                        value={filters.from}
                        onChange={(event) => onChange({ from: event.currentTarget.value })}
                        class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                    />
                </label>
                <label class="flex items-center gap-1 text-xs text-zinc-500">
                    To
                    <input
                        type="datetime-local"
                        step="1"
                        value={filters.to}
                        onChange={(event) => onChange({ to: event.currentTarget.value })}
                        class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                    />
                </label>
                <button
                    type="button"
                    class={toggleClasses(false)}
                    title="Change the sort order"
                    onClick={() => onChange({ order: filters.order === 'newest' ? 'oldest' : 'newest' })}
                >
                    {filters.order === 'newest' ? 'Newest first' : 'Oldest first'}
                </button>
            </div>
            )}
            <div class="flex flex-wrap items-center gap-1.5">
                {levels.map((level) => {
                    const active = filters.levels.includes(level);

                    return (
                        <button
                            key={level}
                            type="button"
                            aria-pressed={active}
                            onClick={() => toggleLevel(level)}
                            class={`rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-2 transition ${levelClasses(level)} ${
                                active ? 'ring-zinc-900 dark:ring-zinc-100' : 'ring-transparent opacity-80 hover:opacity-100'
                            }`}
                        >
                            {level} <span class="tabular-nums opacity-75">{(levelCounts[level] ?? 0).toLocaleString()}</span>
                        </button>
                    );
                })}
                {!levelsOnly && (filters.levels.length > 0 || filters.query !== '' || filters.from !== '' || filters.to !== '') && (
                    <button type="button" class="ml-1 text-xs text-zinc-500 underline hover:text-zinc-900 dark:hover:text-zinc-100" onClick={onClear}>
                        Clear filters
                    </button>
                )}
            </div>
        </div>
    );
}
