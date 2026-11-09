/**
 * The shapes of the JSON the Vlog API returns.
 */

export interface LogFileSummary {
    id: string;
    name: string;
    path: string;
    folder: string;
    size: number;
    modified_at: string;
}

export interface LogFileDetails extends LogFileSummary {
    format: string;
    entries: number;
    levels: Record<string, number>;
    indexed_bytes: number;
    complete: boolean;
}

export interface StackFrame {
    index: number;
    file: string | null;
    line: number | null;
    call: string | null;
}

export interface LogEntry {
    offset: number;
    length: number;
    truncated: boolean;
    level: string | null;
    raw_level: string | null;
    time: string | null;
    channel: string | null;
    message: string;
    context: Record<string, unknown> | unknown[] | null;
    stack: StackFrame[];
    /** Where a logged exception was thrown, when the entry has one. */
    origin: { file: string; line: number } | null;
}

export interface PageMeta {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
    complete: boolean;
}

export interface SearchMeta {
    next_cursor: number | null;
    has_more: boolean;
    reached_time_limit: boolean;
    complete: boolean;
}

export interface EntriesResponse {
    data: LogEntry[];
    meta: PageMeta;
}

export interface SearchResponse {
    data: LogEntry[];
    meta: SearchMeta;
}

/**
 * A group of similar entries, such as every "Order {n} failed" error.
 */
export interface LogGroup {
    id: number;
    level: string | null;
    pattern: string;
    sample: string;
    count: number;
    first_seen: string | null;
    last_seen: string | null;
    trend: number[];
}

/**
 * One file's matches in a search of every file.
 */
export interface FileMatches {
    file: LogFileSummary;
    entries: LogEntry[];
    has_more: boolean;
    complete: boolean;
}

export interface SearchAllResponse {
    data: FileMatches[];
    meta: { searched_files: number; total_files: number; next_after: string | null };
}

/**
 * The lines of source code around a stack frame's line.
 */
export interface SourceLines {
    path: string;
    line: number;
    start: number;
    lines: string[];
}

export interface GroupsResponse {
    data: LogGroup[];
    meta: { total: number; trend_days: string[]; complete: boolean };
}

/**
 * Settings the server writes into the page.
 */
export interface VlogConfig {
    apiUrl: string;
    title: string;
    canManage: boolean;
    canPreviewSource: boolean;
    csrfToken: string | null;
    /** The editor the server suggests for file links, such as "vscode". */
    editor: string | null;
}

/**
 * A saved entry with a note. The entry is a copy, so it stays readable after
 * its log file is rotated or deleted.
 */
export interface Bookmark {
    id: string;
    file_id: string;
    file: string;
    offset: number;
    note: string;
    created_at: string;
    entry: LogEntry;
}
