import { useEffect, useState } from 'preact/hooks';
import { ApiError, type Api } from '../api';
import { formatTime, levelClasses } from '../format';
import type { LogGroup } from '../types';

export type GroupSort = 'count' | 'last_seen' | 'first_seen';

interface Props {
    api: Api;
    fileId: string;
    levels: string[];
    reloadKey: number;
    onOpen: (group: LogGroup) => void;
}

/**
 * Groups of similar entries, with how often each occurred, when it was
 * first and last seen, and a daily trend.
 */
export function Groups({ api, fileId, levels, reloadKey, onOpen }: Props) {
    const [sort, setSort] = useState<GroupSort>('count');
    const [groups, setGroups] = useState<LogGroup[] | null>(null);
    const [days, setDays] = useState<string[]>([]);
    const [total, setTotal] = useState(0);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        setError(null);

        api.groups(fileId, { levels: levels.join(','), sort, limit: '200' }, controller.signal)
            .then((response) => {
                setGroups(response.data);
                setDays(response.meta.trend_days);
                setTotal(response.meta.total);
            })
            .catch((caught: unknown) => {
                if (!(caught instanceof DOMException && caught.name === 'AbortError')) {
                    setError(caught instanceof ApiError ? caught.message : 'Vlog could not load the groups.');
                }
            });

        return () => controller.abort();
    }, [api, fileId, levels.join(','), sort, reloadKey]);

    if (error !== null) {
        return <p role="alert" class="m-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{error}</p>;
    }

    if (groups === null) {
        return <p class="p-4 text-center text-sm text-zinc-500">Loading groups…</p>;
    }

    if (groups.length === 0) {
        return <p class="p-8 text-center text-sm text-zinc-500">No groups match these levels.</p>;
    }

    return (
        <div>
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-xs text-zinc-500">
                <span>
                    {total.toLocaleString()} {total === 1 ? 'group' : 'groups'}
                    {total > groups.length && ` · showing the first ${groups.length.toLocaleString()}`}
                </span>
                <label class="flex items-center gap-1">
                    Sort by
                    <select
                        value={sort}
                        onChange={(event) => setSort(event.currentTarget.value as GroupSort)}
                        class="rounded-md border border-zinc-300 bg-white px-2 py-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        <option value="count">Most occurrences</option>
                        <option value="last_seen">Most recent</option>
                        <option value="first_seen">Newest first seen</option>
                    </select>
                </label>
            </div>
            <ul class="divide-y divide-zinc-200 border-t border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                {groups.map((group) => (
                    <li key={group.id}>
                        <button
                            type="button"
                            onClick={() => onOpen(group)}
                            title="Show these entries"
                            class="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-zinc-50 dark:hover:bg-zinc-900"
                        >
                            <span class={`w-20 shrink-0 rounded px-1.5 py-0.5 text-center text-[11px] font-semibold uppercase ${levelClasses(group.level)}`}>
                                {group.level ?? 'none'}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-mono text-sm">{group.pattern || <em class="text-zinc-400">No message</em>}</span>
                                <span class="block truncate text-xs text-zinc-500">
                                    Last seen {formatTime(group.last_seen)} · first seen {formatTime(group.first_seen)}
                                </span>
                            </span>
                            <Sparkline values={group.trend} days={days} />
                            <span class="w-16 shrink-0 text-right font-mono text-sm font-semibold tabular-nums">
                                {group.count.toLocaleString()}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * A small bar chart of daily counts.
 */
function Sparkline({ values, days }: { values: number[]; days: string[] }) {
    const max = Math.max(1, ...values);
    const barWidth = 4;
    const gap = 1;
    const height = 20;
    const label = values.map((value, index) => `${days[index] ?? ''}: ${value}`).join(', ');

    return (
        <svg
            width={values.length * (barWidth + gap)}
            height={height}
            role="img"
            aria-label={`Daily occurrences: ${label}`}
            class="hidden shrink-0 text-zinc-400 sm:block dark:text-zinc-500"
        >
            <title>{label}</title>
            {values.map((value, index) => {
                const barHeight = value === 0 ? 1 : Math.max(2, Math.round((value / max) * height));

                return (
                    <rect
                        key={index}
                        x={index * (barWidth + gap)}
                        y={height - barHeight}
                        width={barWidth}
                        height={barHeight}
                        rx={1}
                        fill="currentColor"
                        opacity={value === 0 ? 0.3 : 1}
                    />
                );
            })}
        </svg>
    );
}
