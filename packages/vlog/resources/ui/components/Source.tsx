import { createContext } from 'preact';
import { useContext, useEffect, useState } from 'preact/hooks';
import { ApiError, type Api } from '../api';
import { editorUrl, isLocalPath, isVendorPath, type EditorSettings } from '../editor';
import type { SourceLines, StackFrame } from '../types';

/**
 * What stack frames need to link to the editor and preview code.
 */
export interface DevTools {
    api: Api | null;
    canPreviewSource: boolean;
    editor: EditorSettings;
}

export const DevToolsContext = createContext<DevTools>({
    api: null,
    canPreviewSource: false,
    editor: { editor: 'vscode', serverPath: '', localPath: '' },
});

/**
 * A file and line from a stack trace, with a link that opens it in the
 * editor and a button that shows the code around the line.
 */
export function FileLocation({ file, line, call = null }: { file: string; line: number | null; call?: string | null }) {
    const { api, canPreviewSource, editor } = useContext(DevToolsContext);
    const [open, setOpen] = useState(false);
    const href = editorUrl(file, line, editor);
    const canPreview = api !== null && canPreviewSource && line !== null && isLocalPath(file);

    return (
        <div class="min-w-0">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class={`break-all ${isVendorPath(file) ? 'text-zinc-500' : 'text-zinc-900 dark:text-zinc-100'}`}>
                    {file}
                    {line !== null && <span class="text-zinc-500">:{line}</span>}
                </span>
                {canPreview && (
                    <button
                        type="button"
                        aria-expanded={open}
                        onClick={() => setOpen(!open)}
                        class="text-[11px] text-zinc-500 underline hover:text-zinc-900 dark:hover:text-zinc-100"
                    >
                        {open ? 'Hide code' : 'Code'}
                    </button>
                )}
                {href !== null && (
                    <a href={href} class="text-[11px] text-zinc-500 underline hover:text-zinc-900 dark:hover:text-zinc-100" title="Open in your editor">
                        Open
                    </a>
                )}
            </div>
            {call !== null && <span class="block break-all text-zinc-500">{call}</span>}
            {open && api !== null && line !== null && <SourceSnippet api={api} file={file} line={line} />}
        </div>
    );
}

function SourceSnippet({ api, file, line }: { api: Api; file: string; line: number }) {
    const [source, setSource] = useState<SourceLines | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const controller = new AbortController();

        api.source(file, line, controller.signal)
            .then(({ data }) => setSource(data))
            .catch((caught: unknown) => {
                if (!(caught instanceof DOMException && caught.name === 'AbortError')) {
                    setError(caught instanceof ApiError ? caught.message : 'Vlog could not load the code.');
                }
            });

        return () => controller.abort();
    }, [api, file, line]);

    if (error !== null) {
        return <p class="mt-1 text-[11px] text-zinc-500">{error}</p>;
    }

    if (source === null) {
        return <p class="mt-1 text-[11px] text-zinc-500">Loading code…</p>;
    }

    return (
        <div class="mt-1 overflow-x-auto rounded-md border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <table class="w-full border-collapse font-mono text-[11px] leading-5">
                <tbody>
                    {source.lines.map((text, index) => {
                        const number = source.start + index;
                        const current = number === source.line;

                        return (
                            <tr key={number} class={current ? 'bg-red-50 dark:bg-red-950/60' : undefined}>
                                <td class="w-10 pr-2 pl-2 text-right align-top text-zinc-400 select-none">{number}</td>
                                <td class={`pr-3 whitespace-pre ${current ? 'text-red-800 dark:text-red-300' : ''}`}>{text || ' '}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/**
 * A stack trace. When it has frames from the application, frames from
 * dependencies are hidden until asked for.
 */
export function StackTrace({ frames }: { frames: StackFrame[] }) {
    const [showAll, setShowAll] = useState(false);
    const isApp = (frame: StackFrame) => frame.file !== null && !isVendorPath(frame.file);
    const appFrames = frames.filter(isApp).length;
    const collapsible = appFrames > 0 && appFrames < frames.length;
    const visible = collapsible && !showAll ? frames.filter(isApp) : frames;

    return (
        <div>
            <ol class="space-y-1 font-mono text-xs">
                {visible.map((frame) => (
                    <li key={frame.index} class="flex gap-3">
                        <span class="w-6 shrink-0 text-right text-zinc-400">#{frame.index}</span>
                        {frame.file !== null ? (
                            <FileLocation file={frame.file} line={frame.line} call={frame.call} />
                        ) : (
                            <span class="min-w-0 break-all text-zinc-500">{frame.call}</span>
                        )}
                    </li>
                ))}
            </ol>
            {collapsible && (
                <button
                    type="button"
                    onClick={() => setShowAll(!showAll)}
                    class="mt-2 text-xs text-zinc-500 underline hover:text-zinc-900 dark:hover:text-zinc-100"
                >
                    {showAll
                        ? 'Show only application frames'
                        : `Show all ${frames.length} frames (${frames.length - appFrames} from dependencies)`}
                </button>
            )}
        </div>
    );
}
