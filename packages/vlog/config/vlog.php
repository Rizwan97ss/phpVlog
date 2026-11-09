<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Vlog
    |--------------------------------------------------------------------------
    |
    | When disabled, Vlog registers no routes.
    |
    */

    'enabled' => env('VLOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Route Path and Domain
    |--------------------------------------------------------------------------
    |
    | The URI path where Vlog is available, and an optional domain to serve it
    | from. Change the path if it clashes with one of your own routes.
    |
    */

    'path' => env('VLOG_PATH', 'vlog'),

    'domain' => env('VLOG_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to every Vlog route. Access is always checked with the
    | "viewVlog" gate after these run. By default, the gate only allows access
    | in the local environment. Define your own "viewVlog" gate to change it.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Log Folders
    |--------------------------------------------------------------------------
    |
    | Only files in these folders, and their subfolders up to "max_depth"
    | levels deep, that match one of the patterns can be viewed.
    |
    */

    'paths' => [
        storage_path('logs'),
    ],

    'patterns' => ['*.log'],

    'max_depth' => 3,

    /*
    |--------------------------------------------------------------------------
    | Hiding Secrets
    |--------------------------------------------------------------------------
    |
    | Before entries are shown, values under secret-looking context keys (such
    | as "password", "token" or "api_key") and common secret formats in text
    | (such as "password=...", bearer tokens and JWTs) are replaced with
    | "[redacted]". Add your own context keys, and regular expressions with
    | delimiters, to hide more. Search still matches the original text.
    |
    */

    'redact' => [
        'enabled' => env('VLOG_REDACT', true),
        'keys' => [],
        'patterns' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Index Storage
    |--------------------------------------------------------------------------
    |
    | Where Vlog stores the indexes that make large log files fast to browse.
    | Indexes can be deleted at any time and are rebuilt when needed.
    |
    */

    'index_path' => storage_path('framework/vlog'),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | "time_limit" is the most seconds one request spends indexing or
    | searching. Larger files finish over several requests. Entries longer
    | than "max_entry_bytes" are truncated.
    |
    */

    'time_limit' => 2.0,

    'max_entry_bytes' => 1048576,

    /*
    |--------------------------------------------------------------------------
    | Browser Errors
    |--------------------------------------------------------------------------
    |
    | Vlog can add a small script to your application's HTML pages that
    | reports JavaScript errors, unhandled promise rejections and files that
    | fail to load. They're written to "path", which Vlog shows like any other
    | log. When "enabled" is null, this only happens in the local environment.
    |
    */

    'browser_errors' => [
        'enabled' => env('VLOG_BROWSER_ERRORS'),
        'path' => storage_path('logs/browser-errors.log'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Previews and Editor Links
    |--------------------------------------------------------------------------
    |
    | Users the "manageVlog" gate allows can preview the code around each
    | stack frame. Only PHP files inside "paths" are shown, never hidden files
    | such as ".env". "editor" is the editor that file links open in, such as
    | "vscode" or "phpstorm". Each user can also choose their own in Vlog.
    |
    */

    'source' => [
        'enabled' => env('VLOG_SOURCE_PREVIEW', true),
        'paths' => [base_path()],
    ],

    'editor' => env('VLOG_EDITOR'),

];
