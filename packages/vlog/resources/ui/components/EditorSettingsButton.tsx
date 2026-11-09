import { useEffect, useLayoutEffect, useRef, useState } from 'preact/hooks';
import { editors, mapPath, type EditorSettings } from '../editor';

/**
 * The panel's width in pixels, when the window is wide enough.
 */
const PANEL_WIDTH = 288;

interface Props {
    settings: EditorSettings;
    onChange: (settings: EditorSettings) => void;
}

/**
 * A button that opens the settings for links that open files in an editor.
 */
export function EditorSettingsButton({ settings, onChange }: Props) {
    const [open, setOpen] = useState(false);
    const panel = useRef<HTMLDivElement>(null);
    const button = useRef<HTMLButtonElement>(null);
    const [position, setPosition] = useState<{ top: number; left: number; width: number; maxHeight: number } | null>(null);

    // Place the panel below the button, kept inside the window: it lines up
    // with the button's right edge when there's room, and otherwise moves
    // left, or shrinks on narrow screens.
    useLayoutEffect(() => {
        if (!open) {
            setPosition(null);

            return;
        }

        const place = () => {
            const rect = button.current?.getBoundingClientRect();

            if (rect === undefined) {
                return;
            }

            const margin = 8;
            const width = Math.min(PANEL_WIDTH, window.innerWidth - margin * 2);
            const left = Math.min(Math.max(margin, rect.right - width), window.innerWidth - width - margin);
            const top = rect.bottom + 4;

            setPosition({ top, left, width, maxHeight: Math.max(160, window.innerHeight - top - margin) });
        };

        place();
        window.addEventListener('resize', place);

        return () => window.removeEventListener('resize', place);
    }, [open]);

    // Close the panel when clicking outside it or pressing Escape.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onClick = (event: MouseEvent) => {
            if (panel.current !== null && !panel.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const onKeyDown = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);

        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    const mapping = settings.serverPath.trim() !== '' && settings.localPath.trim() !== '';
    const examplePath = `${settings.serverPath.trim().replace(/[\\/]+$/, '')}/app/Models/User.php`;

    const inputClasses =
        'w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-900';

    return (
        <div class="relative" ref={panel}>
            <button
                ref={button}
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                title="Editor settings"
                aria-label="Editor settings"
                class="rounded-md px-2 py-1 text-sm text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
            >
                ⚙
            </button>
            {open && position !== null && (
                <div
                    style={{ top: `${position.top}px`, left: `${position.left}px`, width: `${position.width}px`, maxHeight: `${position.maxHeight}px` }}
                    class="fixed z-20 space-y-3 overflow-y-auto rounded-md border border-zinc-200 bg-white p-3 text-sm shadow-lg dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <label class="block space-y-1">
                        <span class="text-xs font-medium">Open files in</span>
                        <select
                            value={settings.editor}
                            onChange={(event) => onChange({ ...settings, editor: event.currentTarget.value })}
                            class={inputClasses}
                        >
                            {editors.map((editor) => (
                                <option key={editor.id} value={editor.id}>{editor.name}</option>
                            ))}
                        </select>
                    </label>
                    <details open={mapping} class="group border-t border-zinc-200 pt-3 dark:border-zinc-800">
                        <summary class="flex cursor-pointer list-none items-center gap-1.5 text-xs font-medium select-none [&::-webkit-details-marker]:hidden">
                            <span aria-hidden="true" class="text-zinc-400 transition-transform group-open:rotate-90">▸</span>
                            Path mapping
                            {mapping ? (
                                <span class="ml-auto rounded-full bg-emerald-100 px-1.5 py-px text-[10px] font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                    On
                                </span>
                            ) : (
                                <span class="ml-auto text-[10px] font-normal text-zinc-400">Optional</span>
                            )}
                        </summary>
                        <div class="mt-2 space-y-2">
                            <p class="text-[11px] leading-snug text-zinc-500">
                                Only needed when the application runs in Docker, a virtual machine or on another server, so
                                its file paths differ from the ones on this computer.
                            </p>
                            <label class="block space-y-1">
                                <span class="text-[11px] font-medium text-zinc-600 dark:text-zinc-300">Path on the server</span>
                                <input
                                    type="text"
                                    value={settings.serverPath}
                                    placeholder="/var/www/html"
                                    spellcheck={false}
                                    onInput={(event) => onChange({ ...settings, serverPath: event.currentTarget.value })}
                                    class={`${inputClasses} font-mono`}
                                />
                            </label>
                            <div aria-hidden="true" class="text-center text-xs leading-none text-zinc-400">↓</div>
                            <label class="block space-y-1">
                                <span class="text-[11px] font-medium text-zinc-600 dark:text-zinc-300">Path on this computer</span>
                                <input
                                    type="text"
                                    value={settings.localPath}
                                    placeholder="/Users/you/Code/app"
                                    spellcheck={false}
                                    onInput={(event) => onChange({ ...settings, localPath: event.currentTarget.value })}
                                    class={`${inputClasses} font-mono`}
                                />
                            </label>
                            {mapping && (
                                <div class="space-y-0.5 rounded-md bg-zinc-50 px-2 py-1.5 font-mono text-[10px] leading-snug break-all dark:bg-zinc-950">
                                    <div class="font-sans text-[10px] text-zinc-500">For example</div>
                                    <div class="text-zinc-500">{examplePath}</div>
                                    <div class="text-zinc-900 dark:text-zinc-100">→ {mapPath(examplePath, settings)}</div>
                                </div>
                            )}
                            {(settings.serverPath !== '' || settings.localPath !== '') && (
                                <button
                                    type="button"
                                    onClick={() => onChange({ ...settings, serverPath: '', localPath: '' })}
                                    class="text-[11px] text-zinc-500 underline hover:text-zinc-900 dark:hover:text-zinc-100"
                                >
                                    Remove mapping
                                </button>
                            )}
                        </div>
                    </details>
                </div>
            )}
        </div>
    );
}
