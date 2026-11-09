/**
 * Links that open a file at a line in the developer's editor.
 */

export interface Editor {
    id: string;
    name: string;
    /** The link, with {file} and {line} placeholders. */
    url: string;
}

export const editors: Editor[] = [
    { id: 'vscode', name: 'VS Code', url: 'vscode://file/{file}:{line}' },
    { id: 'vscode-insiders', name: 'VS Code Insiders', url: 'vscode-insiders://file/{file}:{line}' },
    { id: 'cursor', name: 'Cursor', url: 'cursor://file/{file}:{line}' },
    { id: 'windsurf', name: 'Windsurf', url: 'windsurf://file/{file}:{line}' },
    { id: 'zed', name: 'Zed', url: 'zed://file/{file}:{line}' },
    { id: 'phpstorm', name: 'PhpStorm', url: 'phpstorm://open?file={file}&line={line}' },
    { id: 'idea', name: 'IntelliJ IDEA', url: 'idea://open?file={file}&line={line}' },
    { id: 'sublime', name: 'Sublime Text', url: 'subl://open?url=file://{file}&line={line}' },
    { id: 'nova', name: 'Nova', url: 'nova://open?path={file}&line={line}' },
    { id: 'textmate', name: 'TextMate', url: 'txmt://open?url=file://{file}&line={line}' },
    { id: 'macvim', name: 'MacVim', url: 'mvim://open?url=file://{file}&line={line}' },
    { id: 'emacs', name: 'Emacs', url: 'emacs://open?url=file://{file}&line={line}' },
];

/**
 * The editor and path mapping a user chose. The mapping replaces the start
 * of a server path with a local one, for applications running in Docker or
 * on another machine, such as "/var/www/html" with "/Users/me/app".
 */
export interface EditorSettings {
    editor: string;
    serverPath: string;
    localPath: string;
}

const STORAGE_KEY = 'vlog.editor';

/**
 * Determine whether a path is an absolute path on disk, rather than a URL
 * or a placeholder such as "[internal function]".
 */
export function isLocalPath(path: string): boolean {
    return path.startsWith('/') || /^[A-Za-z]:[\\/]/.test(path);
}

/**
 * Determine whether a path is inside Composer's or npm's dependencies.
 */
export function isVendorPath(path: string): boolean {
    return /[\\/](vendor|node_modules)[\\/]/.test(path);
}

/**
 * Apply the path mapping to a server path.
 */
export function mapPath(path: string, settings: EditorSettings): string {
    const from = settings.serverPath.trim();

    if (from === '' || !path.startsWith(from)) {
        return path;
    }

    const rest = path.slice(from.length);

    if (rest !== '' && !rest.startsWith('/') && !rest.startsWith('\\')) {
        return path;
    }

    return settings.localPath.trim().replace(/[\\/]+$/, '') + rest;
}

/**
 * Build the link that opens a file at a line, or null when the path isn't
 * a file on disk or the editor isn't known.
 */
export function editorUrl(path: string, line: number | null, settings: EditorSettings): string | null {
    const editor = editors.find((candidate) => candidate.id === settings.editor);

    if (editor === undefined || !isLocalPath(path)) {
        return null;
    }

    const file = mapPath(path, settings)
        .replace(/\\/g, '/')
        .split('/')
        .map((segment) => encodeURIComponent(segment).replace(/%3A/gi, ':'))
        .join('/');

    return editor.url.replace('{file}', file).replace('{line}', String(line ?? 1));
}

/**
 * Read the user's editor settings, falling back to the server's editor and
 * then VS Code.
 */
export function loadEditorSettings(serverEditor: string | null): EditorSettings {
    const fallback: EditorSettings = {
        editor: editors.some((editor) => editor.id === serverEditor) ? (serverEditor as string) : 'vscode',
        serverPath: '',
        localPath: '',
    };

    try {
        const saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null') as Partial<EditorSettings> | null;

        if (saved === null || typeof saved !== 'object') {
            return fallback;
        }

        return {
            editor: typeof saved.editor === 'string' && editors.some((editor) => editor.id === saved.editor) ? saved.editor : fallback.editor,
            serverPath: typeof saved.serverPath === 'string' ? saved.serverPath : '',
            localPath: typeof saved.localPath === 'string' ? saved.localPath : '',
        };
    } catch {
        return fallback;
    }
}

export function saveEditorSettings(settings: EditorSettings): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch {
        // Settings then last until the page is closed.
    }
}
