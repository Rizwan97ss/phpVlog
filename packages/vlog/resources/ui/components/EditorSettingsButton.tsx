import { useEffect, useRef, useState } from 'preact/hooks';
import { editors, type EditorSettings } from '../editor';

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

    const inputClasses =
        'w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-900';

    return (
        <div class="relative" ref={panel}>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                title="Editor settings"
                aria-label="Editor settings"
                class="rounded-md px-2 py-1 text-sm text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
            >
                ⚙
            </button>
            {open && (
                <div class="absolute top-full right-0 z-10 mt-1 w-72 space-y-3 rounded-md border border-zinc-200 bg-white p-3 text-sm shadow-lg dark:border-zinc-800 dark:bg-zinc-900">
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
                    <fieldset class="space-y-1">
                        <legend class="text-xs font-medium">Path on the server, and on this computer</legend>
                        <p class="text-[11px] text-zinc-500">
                            Only needed when the application runs in Docker or on another machine.
                        </p>
                        <input
                            type="text"
                            value={settings.serverPath}
                            placeholder="/var/www/html"
                            aria-label="Path on the server"
                            onInput={(event) => onChange({ ...settings, serverPath: event.currentTarget.value })}
                            class={`${inputClasses} font-mono`}
                        />
                        <input
                            type="text"
                            value={settings.localPath}
                            placeholder="/Users/you/Code/app"
                            aria-label="Path on this computer"
                            onInput={(event) => onChange({ ...settings, localPath: event.currentTarget.value })}
                            class={`${inputClasses} font-mono`}
                        />
                    </fieldset>
                </div>
            )}
        </div>
    );
}
